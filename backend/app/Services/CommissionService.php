<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use RuntimeException;

class CommissionService
{
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
                Contract::with('sourceQuotation')->whereNotNull('source_quotation_id')->each(function (Contract $contract) use ($rule, &$count) {
                    $user = User::find($contract->sourceQuotation?->created_by);
                    if ($user?->role === $rule->applies_to_role && ! CommissionEntry::where('commission_rule_id', $rule->id)->where('contract_id', $contract->id)->exists()) {
                        $this->create($rule, $user, $contract, null, null);
                        $count++;
                    }
                });
            } elseif ($rule->basis === 'collected_payment') {
                Payment::with('installment.contract.sourceQuotation')->each(function (Payment $payment) use ($rule, &$count) {
                    $user = User::find($payment->installment?->contract?->sourceQuotation?->created_by);
                    if ($user?->role === $rule->applies_to_role && ! CommissionEntry::where('commission_rule_id', $rule->id)->where('payment_id', $payment->id)->exists()) {
                        $this->create($rule, $user, $payment->installment->contract, null, $payment);
                        $count++;
                    }
                });
            }
        }

        return $count;
    }

    public function create(CommissionRule $rule, User $user, ?Contract $contract, ?Visit $visit, ?Payment $payment): CommissionEntry
    {
        if (! $rule->is_active || $rule->applies_to_role !== $user->role) {
            throw new RuntimeException('قاعدة العمولة لا تنطبق على دور هذا المستخدم.');
        }
        $basis = match ($rule->basis) {
            'contract_value' => $contract?->priceInclVat(),
            'collected_payment' => $payment ? (float) $payment->amount : null,
            'completed_visit' => $visit?->isClosed() ? 1.0 : null,
            default => null,
        };
        if ($basis === null) {
            throw new RuntimeException('المستند المطلوب لأساس العمولة غير موجود أو غير مكتمل.');
        }
        $amount = round($basis * ((float) $rule->rate / 100) + (float) $rule->fixed_amount, 2);

        return CommissionEntry::create([
            'commission_rule_id' => $rule->id, 'user_id' => $user->id,
            'contract_id' => $contract?->id, 'visit_id' => $visit?->id, 'payment_id' => $payment?->id,
            'basis_amount' => $basis, 'commission_amount' => $amount, 'status' => 'pending',
        ]);
    }
}
