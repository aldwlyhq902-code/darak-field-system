<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Payment;
use App\Models\Quotation;
use App\Support\BusinessReference;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommercialService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommissionService $commissions,
    ) {}

    public function convertQuotation(Quotation $quotation, int $actorId): Contract
    {
        return DB::transaction(function () use ($quotation, $actorId): Contract {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->converted_contract_id !== null) {
                return $quotation->convertedContract;
            }

            if (! in_array($quotation->status, [Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED], true)) {
                throw new RuntimeException('يجب إرسال العرض أو اعتماده قبل تحويله إلى عقد.');
            }

            if ($quotation->valid_until->isPast() && $quotation->status !== Quotation::STATUS_ACCEPTED) {
                throw new RuntimeException('انتهت صلاحية العرض ولم يعتمد العميل هذا الإصدار.');
            }

            $contract = Contract::create([
                'client_id' => $quotation->client_id,
                'contract_no' => BusinessReference::make('DK', false),
                'package_code' => $quotation->package_code,
                'price_amount' => $quotation->price_amount,
                'vat_rate' => $quotation->vat_rate,
                'billing_cycle' => $quotation->billing_cycle,
                'starts_on' => $quotation->starts_on,
                'ends_on' => $quotation->starts_on->copy()->addMonths($quotation->duration_months)->subDay(),
                'service_window_start' => $quotation->service_window_start,
                'service_window_end' => $quotation->service_window_end,
                'sla_minutes' => $quotation->sla_minutes,
                'exclusions' => $quotation->terms,
                'status' => 'active',
                'source_quotation_id' => $quotation->id,
            ]);
            $contract->sites()->sync($quotation->sites()->pluck('sites.id'));
            $this->generateInstallments($contract);

            $quotation->forceFill([
                'converted_contract_id' => $contract->id,
                'status' => Quotation::STATUS_CONVERTED,
            ])->save();

            $this->audit->record('quotation.converted', $quotation, null, [
                'contract_id' => $contract->id, 'actor_id' => $actorId,
            ]);

            return $contract;
        });
    }

    /**
     * Builds the payment schedule so the installments add up EXACTLY to the
     * contract value, pre-VAT and VAT alike: shares are computed with Money and
     * the last installment absorbs the rounding remainder. A down payment is
     * part of that value (it was previously billed on top of a periodic plan).
     */
    public function generateInstallments(Contract $contract): void
    {
        if ($contract->installments()->exists()) {
            return;
        }

        $quotation = $contract->source_quotation_id ? Quotation::find($contract->source_quotation_id) : null;
        $total = $this->contractTotal($contract);
        $downPayment = Money::of($quotation?->down_payment_amount);
        if (Money::compare($downPayment, $total) > 0) {
            throw new RuntimeException('الدفعة المقدمة تتجاوز قيمة العقد.');
        }

        /** @var array<int, array{0: \DateTimeInterface, 1: string}> $rows */
        $rows = [];
        if (Money::isPositive($downPayment)) {
            $rows[] = [$contract->starts_on, $downPayment];
        }
        $remaining = Money::sub($total, $downPayment);

        if ($quotation && $quotation->billing_cycle === 'custom' && ($quotation->custom_installments ?? []) !== []) {
            foreach ($quotation->custom_installments as $row) {
                $rows[] = [CarbonImmutable::parse($row['due_on']), Money::of($row['amount'])];
            }
        } elseif (! in_array($contract->billing_cycle, ['monthly', 'quarterly'], true)) {
            if (Money::isPositive($remaining) || $rows === []) {
                $rows[] = [$contract->starts_on, $remaining];
            }
        } elseif (Money::isPositive($remaining)) {
            $months = $this->contractMonths($contract);
            $stepMonths = $contract->billing_cycle === 'quarterly' ? 3 : 1;
            // A partial final period is billed for its months only, not a full cycle.
            $weights = [];
            for ($start = 0; $start < $months; $start += $stepMonths) {
                $weights[$start] = min($stepMonths, $months - $start);
            }
            foreach (Money::allocate($remaining, $weights) as $startMonth => $amount) {
                $rows[] = [$contract->starts_on->copy()->addMonths($startMonth), $amount];
            }
        }

        // The last row absorbs whatever separates the schedule from the total.
        $lastIndex = array_key_last($rows);
        $rows[$lastIndex][1] = Money::sub($total, Money::sum(array_column(array_slice($rows, 0, -1), 1)));
        if (Money::isNegative($rows[$lastIndex][1])) {
            throw new RuntimeException('مجموع الدفعات المخصصة يتجاوز قيمة العقد.');
        }

        $totalVat = Money::vat($total, $contract->vat_rate);
        $vatSoFar = Money::zero();
        foreach ($rows as $index => [$dueOn, $amount]) {
            $vat = $index === $lastIndex ? Money::sub($totalVat, $vatSoFar) : Money::vat($amount, $contract->vat_rate);
            $vatSoFar = Money::add($vatSoFar, $vat);
            $this->createInstallment($contract, $index + 1, $dueOn, $amount, $vat);
        }
    }

    /** Pre-VAT value of the whole contract: monthly/quarterly prices are per month. */
    public function contractTotal(Contract $contract): string
    {
        if (in_array($contract->billing_cycle, ['monthly', 'quarterly'], true)) {
            return Money::mul($contract->price_amount, $this->contractMonths($contract));
        }

        return Money::of($contract->price_amount);
    }

    private function contractMonths(Contract $contract): int
    {
        $end = $contract->ends_on?->copy()->addDay() ?? $contract->starts_on->copy()->addYear();

        return max(1, (int) round($contract->starts_on->diffInMonths($end)));
    }

    private function createInstallment(Contract $contract, int $number, \DateTimeInterface $dueOn, string $amount, string $vat): void
    {
        ContractInstallment::create([
            'contract_id' => $contract->id, 'installment_no' => $number,
            'due_on' => $dueOn, 'amount' => $amount, 'vat_amount' => $vat,
            'total_amount' => Money::add($amount, $vat), 'status' => 'pending',
        ]);
    }

    public function recordPayment(ContractInstallment $installment, array $data, int $actorId): Payment
    {
        $payment = DB::transaction(function () use ($installment, $data, $actorId): Payment {
            $installment = ContractInstallment::query()->lockForUpdate()->findOrFail($installment->id);
            $amount = Money::of($data['amount']);
            if (! Money::isPositive($amount) || Money::compare($amount, $installment->remaining()) > 0) {
                throw new RuntimeException('المبلغ يجب أن يكون أكبر من صفر ولا يتجاوز المتبقي على الدفعة.');
            }

            $payment = Payment::create([
                'receipt_no' => BusinessReference::make('RCPT'),
                'contract_installment_id' => $installment->id,
                'client_id' => $installment->contract->client_id,
                'amount' => $amount,
                'paid_on' => $data['paid_on'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $actorId,
            ]);

            $paid = Money::add($installment->paid_amount, $amount);
            $complete = Money::compare($paid, $installment->total_amount) >= 0;
            $installment->forceFill([
                'paid_amount' => $paid,
                'status' => $complete ? 'paid' : 'partial',
                'paid_at' => $complete ? CarbonImmutable::now() : null,
            ])->save();

            $this->audit->record('payment.recorded', $payment, null, [
                'installment_id' => $installment->id, 'amount' => $amount,
            ]);

            return $payment;
        });

        // Collection is the accrual event for collected-payment commissions.
        $this->commissions->accrueForPayment($payment);

        return $payment;
    }

    /**
     * A refund keeps the receipt on record (receipts are never deleted), takes
     * the amount back off the installment and voids the commissions accrued on
     * it while the clawback window is open.
     */
    public function refundPayment(Payment $payment, string $reason, int $actorId): Payment
    {
        $payment = DB::transaction(function () use ($payment, $reason, $actorId): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->isRefunded()) {
                throw new RuntimeException('سند القبض مسترد مسبقًا.');
            }

            $installment = ContractInstallment::query()->lockForUpdate()->findOrFail($payment->contract_installment_id);
            $paid = Money::max(Money::sub($installment->paid_amount, $payment->amount), 0);
            $status = Money::isPositive($paid) ? 'partial' : ($installment->due_on->lt(today()) ? 'overdue' : 'pending');
            $installment->forceFill(['paid_amount' => $paid, 'status' => $status, 'paid_at' => null])->save();

            $payment->forceFill([
                'refunded_at' => CarbonImmutable::now(), 'refund_reason' => $reason, 'refunded_by' => $actorId,
            ])->save();

            $this->audit->record('payment.refunded', $payment, ['refunded_at' => null], [
                'installment_id' => $installment->id, 'amount' => Money::of($payment->amount), 'reason' => $reason,
            ], $actorId);

            return $payment;
        });

        $this->commissions->reverseForPayment($payment, 'استرداد سند القبض '.$payment->receipt_no);

        return $payment;
    }
}
