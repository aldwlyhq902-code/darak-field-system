<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Commission lifecycle (PRD v1.2 binding correction):
 *
 *   accrued  — created when the basis event happens (a receipt is recorded for
 *              collected-payment rules, the contract/visit for the others);
 *   earned   — automatically once the clawback window passes untouched;
 *   paid     — only when the owner records a payout with a reference;
 *   reversed — the payment was refunded / the contract cancelled inside the window.
 *
 * Commissions are never deducted from wages. A clawback discovered after payout
 * becomes a negative ADJUSTMENT that can only offset future commissions; a
 * payout is never negative.
 */
class CommissionService
{
    /** Contract states that void the commissions earned on them. */
    private const VOID_CONTRACT_STATUSES = ['cancelled', 'terminated'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Idempotently creates accrued entries for every active policy. Returns the count created. */
    public function generate(): int
    {
        $count = 0;
        foreach (CommissionRule::where('is_active', true)->get() as $rule) {
            if ($rule->basis === 'completed_visit' && $rule->applies_to_role === User::ROLE_TECHNICIAN) {
                Visit::with('technician')->where('state', Visit::STATE_COMPLETED)->whereNotNull('assigned_user_id')->each(function (Visit $visit) use ($rule, &$count) {
                    if (! CommissionEntry::where('commission_rule_id', $rule->id)->where('visit_id', $visit->id)->exists()) {
                        $this->create($rule, $visit->technician, null, $visit, null);
                        $count++;
                    }
                });
            } elseif ($rule->basis === 'contract_value') {
                Contract::with('sourceQuotation')->whereNotNull('source_quotation_id')
                    ->whereNotIn('status', self::VOID_CONTRACT_STATUSES)
                    ->each(function (Contract $contract) use ($rule, &$count) {
                        $user = User::find($contract->sourceQuotation?->created_by);
                        if ($user?->role === $rule->applies_to_role && ! CommissionEntry::where('commission_rule_id', $rule->id)->where('contract_id', $contract->id)->exists()) {
                            $this->create($rule, $user, $contract, null, null);
                            $count++;
                        }
                    });
            } elseif ($rule->basis === 'collected_payment') {
                Payment::with('installment.contract.sourceQuotation')->whereNull('refunded_at')->each(function (Payment $payment) use ($rule, &$count) {
                    if ($this->accrueForPaymentRule($rule, $payment) !== null) {
                        $count++;
                    }
                });
            }
        }

        return $count;
    }

    /**
     * Called as soon as a receipt is recorded: collected-payment commissions
     * accrue at collection time instead of waiting for the nightly run.
     *
     * @return array<int, CommissionEntry>
     */
    public function accrueForPayment(Payment $payment): array
    {
        if ($payment->isRefunded()) {
            return [];
        }

        $payment->loadMissing('installment.contract.sourceQuotation');

        return CommissionRule::where('is_active', true)->where('basis', 'collected_payment')->get()
            ->map(fn (CommissionRule $rule) => $this->accrueForPaymentRule($rule, $payment))
            ->filter()->values()->all();
    }

    public function create(CommissionRule $rule, User $user, ?Contract $contract, ?Visit $visit, ?Payment $payment): CommissionEntry
    {
        if (! $rule->is_active || $rule->applies_to_role !== $user->role) {
            throw new RuntimeException('قاعدة العمولة لا تنطبق على دور هذا المستخدم.');
        }
        if ($payment?->isRefunded()) {
            throw new RuntimeException('لا تُحتسب عمولة على سند قبض مسترد.');
        }
        $basis = match ($rule->basis) {
            'contract_value' => $contract ? $contract->priceInclVat() : null,
            'collected_payment' => $payment ? Money::of($payment->amount) : null,
            'completed_visit' => $visit?->isClosed() ? '1.00' : null,
            default => null,
        };
        if ($basis === null) {
            throw new RuntimeException('المستند المطلوب لأساس العمولة غير موجود أو غير مكتمل.');
        }
        $amount = Money::add(Money::percent($basis, $rule->rate), $rule->fixed_amount);

        return CommissionEntry::create([
            'commission_rule_id' => $rule->id, 'user_id' => $user->id,
            'contract_id' => $contract?->id, 'visit_id' => $visit?->id, 'payment_id' => $payment?->id,
            'basis_amount' => Money::of($basis), 'commission_amount' => $amount,
            'status' => CommissionEntry::STATUS_ACCRUED, 'entry_type' => CommissionEntry::TYPE_COMMISSION,
            'accrued_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Accrued entries whose clawback window has passed become earned. Runs in
     * the nightly darak:commissions-generate, after voided sources are reversed.
     */
    public function promoteEarned(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        return CommissionEntry::query()
            ->where('entry_type', CommissionEntry::TYPE_COMMISSION)
            ->where('status', CommissionEntry::STATUS_ACCRUED)
            ->where('accrued_at', '<=', $now->subDays($this->windowDays()))
            ->update(['status' => CommissionEntry::STATUS_EARNED, 'earned_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Sweeps for refunded receipts and cancelled/deleted contracts and reverses
     * their commissions when the event fell inside the window. Returns how many
     * entries were reversed or adjusted.
     */
    public function reverseVoided(): int
    {
        $count = 0;
        CommissionEntry::query()
            ->where('entry_type', CommissionEntry::TYPE_COMMISSION)
            ->where('status', '!=', CommissionEntry::STATUS_REVERSED)
            ->whereIn('payment_id', Payment::withoutGlobalScopes()->whereNotNull('refunded_at')->select('id'))
            ->each(function (CommissionEntry $entry) use (&$count): void {
                $payment = Payment::withoutGlobalScopes()->find($entry->payment_id);
                if ($payment !== null && $this->reverseEntry($entry, 'استرداد سند القبض '.$payment->receipt_no, $payment->refunded_at) !== null) {
                    $count++;
                }
            });

        CommissionEntry::query()
            ->where('entry_type', CommissionEntry::TYPE_COMMISSION)
            ->where('status', '!=', CommissionEntry::STATUS_REVERSED)
            ->whereIn('contract_id', Contract::withoutGlobalScopes()->withTrashed()
                ->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereIn('status', self::VOID_CONTRACT_STATUSES))
                ->select('id'))
            ->each(function (CommissionEntry $entry) use (&$count): void {
                $contract = Contract::withoutGlobalScopes()->withTrashed()->find($entry->contract_id);
                $at = $contract?->deleted_at ?? $contract?->updated_at;
                if ($contract !== null && $this->reverseEntry($entry, 'إلغاء العقد '.$contract->contract_no, $at) !== null) {
                    $count++;
                }
            });

        return $count;
    }

    /** @return array<int, CommissionEntry> reversed entries or created adjustments */
    public function reverseForPayment(Payment $payment, string $reason, ?CarbonInterface $at = null): array
    {
        return CommissionEntry::query()->where('payment_id', $payment->id)
            ->where('entry_type', CommissionEntry::TYPE_COMMISSION)
            ->where('status', '!=', CommissionEntry::STATUS_REVERSED)
            ->get()
            ->map(fn (CommissionEntry $entry) => $this->reverseEntry($entry, $reason, $at ?? $payment->refunded_at))
            ->filter()->values()->all();
    }

    /**
     * For a contract cancellation flow: reverses every commission tied to the
     * contract (its value and its collections) when inside the window.
     *
     * @return array<int, CommissionEntry>
     */
    public function reverseForContract(Contract $contract, string $reason, ?CarbonInterface $at = null): array
    {
        return CommissionEntry::query()->where('contract_id', $contract->id)
            ->where('entry_type', CommissionEntry::TYPE_COMMISSION)
            ->where('status', '!=', CommissionEntry::STATUS_REVERSED)
            ->get()
            ->map(fn (CommissionEntry $entry) => $this->reverseEntry($entry, $reason, $at))
            ->filter()->values()->all();
    }

    /**
     * Inside the window: an unpaid entry is reversed; a paid one gets a single
     * negative adjustment that offsets future payouts. Outside the window the
     * commission stands. Returns the changed entry/adjustment, or null.
     */
    public function reverseEntry(CommissionEntry $entry, string $reason, ?CarbonInterface $at = null): ?CommissionEntry
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now());

        return DB::transaction(function () use ($entry, $reason, $at): ?CommissionEntry {
            $entry = CommissionEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->isAdjustment() || $entry->status === CommissionEntry::STATUS_REVERSED) {
                return null;
            }

            $accruedAt = CarbonImmutable::instance($entry->accrued_at ?? $entry->created_at);
            if ($at->gt($accruedAt->addDays($this->windowDays()))) {
                return null;
            }

            if ($entry->status !== CommissionEntry::STATUS_PAID) {
                $before = ['status' => $entry->status];
                $entry->forceFill([
                    'status' => CommissionEntry::STATUS_REVERSED,
                    'reversed_at' => CarbonImmutable::now(),
                    'reversal_reason' => mb_substr($reason, 0, 190),
                ])->save();
                $this->audit->record('commission.reversed', $entry, $before, [
                    'status' => CommissionEntry::STATUS_REVERSED, 'reason' => $reason,
                ]);

                return $entry;
            }

            if (CommissionEntry::where('reverses_entry_id', $entry->id)->exists()) {
                return null;
            }

            $adjustment = CommissionEntry::create([
                'commission_rule_id' => $entry->commission_rule_id, 'user_id' => $entry->user_id,
                'contract_id' => $entry->contract_id, 'visit_id' => $entry->visit_id, 'payment_id' => $entry->payment_id,
                'basis_amount' => $entry->basis_amount,
                'commission_amount' => Money::negate($entry->commission_amount),
                'entry_type' => CommissionEntry::TYPE_ADJUSTMENT,
                'reverses_entry_id' => $entry->id,
                // Outstanding from now on, but it can only reduce the next payout.
                'status' => CommissionEntry::STATUS_EARNED,
                'accrued_at' => CarbonImmutable::now(), 'earned_at' => CarbonImmutable::now(),
                'reversal_reason' => mb_substr($reason, 0, 190),
            ]);
            $this->audit->record('commission.clawback_adjustment', $adjustment, null, [
                'reverses_entry_id' => $entry->id,
                'amount' => Money::of($adjustment->commission_amount),
                'reason' => $reason,
            ]);

            return $adjustment;
        });
    }

    /**
     * Owner records a payout (bulk). Selected entries must be EARNED
     * commissions; one payout per beneficiary. Outstanding negative adjustments
     * offset the payout down to zero at most — never below; any remainder waits
     * for the next payout.
     *
     * @param  array<int, int>  $entryIds
     * @param  callable(User): bool  $mayPay  tenancy gate for each beneficiary
     * @return array<int, CommissionPayout>
     */
    public function recordPayout(array $entryIds, string $reference, CarbonInterface $paidOn, User $actor, callable $mayPay, ?string $note = null): array
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === [] || trim($reference) === '') {
            throw new RuntimeException('اختر عمولات مكتسبة وأدخل مرجع الدفع.');
        }

        return DB::transaction(function () use ($entryIds, $reference, $paidOn, $actor, $mayPay, $note): array {
            $entries = CommissionEntry::query()->with('user')->whereIn('id', $entryIds)->lockForUpdate()->get();
            if ($entries->count() !== count($entryIds)) {
                throw new RuntimeException('بعض العمولات المحددة غير موجودة.');
            }
            foreach ($entries as $entry) {
                if ($entry->user === null || ! $mayPay($entry->user)) {
                    throw new AuthorizationException('Commission entry is outside the actor tenancy.');
                }
                if ($entry->isAdjustment() || $entry->status !== CommissionEntry::STATUS_EARNED) {
                    throw new RuntimeException('لا يُصرف إلا ما اكتُسب بعد انتهاء فترة الاسترداد.');
                }
            }

            $payouts = [];
            foreach ($entries->groupBy('user_id') as $userId => $userEntries) {
                $gross = Money::sum($userEntries->pluck('commission_amount'));
                $offset = Money::zero();
                $offsets = [];
                $settled = [];

                $adjustments = CommissionEntry::query()
                    ->where('user_id', $userId)
                    ->where('entry_type', CommissionEntry::TYPE_ADJUSTMENT)
                    ->where('status', CommissionEntry::STATUS_EARNED)
                    ->orderBy('id')->lockForUpdate()->get();
                foreach ($adjustments as $adjustment) {
                    $available = Money::sub($gross, $offset);
                    if (! Money::isPositive($available)) {
                        break;
                    }
                    $outstanding = Money::sub(Money::negate($adjustment->commission_amount), $adjustment->offset_amount);
                    $take = Money::min($outstanding, $available);
                    $offset = Money::add($offset, $take);
                    $offsets[] = ['entry_id' => $adjustment->id, 'amount' => $take];
                    $adjustment->offset_amount = Money::add($adjustment->offset_amount, $take);
                    if (Money::equals($take, $outstanding)) {
                        $adjustment->status = CommissionEntry::STATUS_PAID;
                        $adjustment->paid_at = CarbonImmutable::now();
                        $settled[] = $adjustment;
                    }
                    $adjustment->save();
                }

                $payout = CommissionPayout::create([
                    'user_id' => $userId, 'reference' => trim($reference), 'paid_on' => $paidOn->toDateString(),
                    'gross_amount' => $gross, 'offset_amount' => $offset, 'net_amount' => Money::sub($gross, $offset),
                    'offsets' => $offsets, 'note' => $note, 'recorded_by' => $actor->id,
                ]);
                foreach ($settled as $adjustment) {
                    $adjustment->forceFill(['commission_payout_id' => $payout->id])->save();
                }
                CommissionEntry::query()->whereIn('id', $userEntries->modelKeys())->update([
                    'status' => CommissionEntry::STATUS_PAID, 'paid_at' => CarbonImmutable::now(),
                    'commission_payout_id' => $payout->id, 'updated_at' => CarbonImmutable::now(),
                ]);

                $this->audit->record('commission.payout_recorded', $payout, null, [
                    'user_id' => (int) $userId, 'reference' => $payout->reference,
                    'entry_ids' => $userEntries->modelKeys(), 'gross' => $gross,
                    'offset' => $offset, 'net' => Money::of($payout->net_amount),
                ], $actor->id);
                $payouts[] = $payout;
            }

            return $payouts;
        });
    }

    public function windowDays(): int
    {
        return max(0, (int) config('darak.commission_clawback_days', 30));
    }

    private function accrueForPaymentRule(CommissionRule $rule, Payment $payment): ?CommissionEntry
    {
        if ($payment->isRefunded()) {
            return null;
        }
        $user = User::find($payment->installment?->contract?->sourceQuotation?->created_by);
        if ($user?->role !== $rule->applies_to_role
            || CommissionEntry::where('commission_rule_id', $rule->id)->where('payment_id', $payment->id)->exists()) {
            return null;
        }

        return $this->create($rule, $user, $payment->installment->contract, null, $payment);
    }
}
