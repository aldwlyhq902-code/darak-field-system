<?php

namespace App\Services;

use App\Models\ContractInstallment;
use App\Models\FinancialApproval;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinancialApprovalService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(FinancialApproval $approval, int $actorId): FinancialApproval
    {
        return DB::transaction(function () use ($approval, $actorId) {
            $approval = FinancialApproval::lockForUpdate()->findOrFail($approval->id);
            if ($approval->requested_by === $actorId || $approval->first_approved_by === $actorId) {
                throw new RuntimeException('يجب أن يكون كل اعتماد من مستخدم مختلف عن مقدم الطلب والاعتماد الآخر.');
            }
            if ($approval->status === 'pending_first') {
                $approval->forceFill(['status' => 'pending_second', 'first_approved_by' => $actorId, 'first_approved_at' => now()])->save();
            } elseif ($approval->status === 'pending_second') {
                $this->apply($approval);
                $approval->forceFill(['status' => 'approved', 'second_approved_by' => $actorId, 'second_approved_at' => now()])->save();
            } else {
                throw new RuntimeException('هذا الطلب لا ينتظر اعتمادًا.');
            }
            $this->audit->record('financial_approval.'.$approval->status, $approval, null, ['actor_id' => $actorId], $actorId);

            return $approval;
        });
    }

    private function apply(FinancialApproval $approval): void
    {
        $amount = (float) $approval->amount;
        if ($approval->subject_type === 'quotation' && $approval->action_type === 'discount') {
            $quotation = Quotation::lockForUpdate()->findOrFail($approval->subject_id);
            if ($amount <= 0 || $amount >= (float) $quotation->price_amount) {
                throw new RuntimeException('قيمة الخصم غير صالحة.');
            }
            $quotation->decrement('price_amount', $amount);
        } elseif ($approval->subject_type === 'installment' && $approval->action_type === 'settlement') {
            $installment = ContractInstallment::lockForUpdate()->findOrFail($approval->subject_id);
            if ($amount <= 0 || $amount > $installment->remaining()) {
                throw new RuntimeException('قيمة التسوية غير صالحة.');
            }
            $installment->forceFill(['amount' => max(0, (float) $installment->amount - $amount), 'total_amount' => max((float) $installment->paid_amount, (float) $installment->total_amount - $amount)])->save();
        } else {
            throw new RuntimeException('نوع إجراء الاعتماد غير مدعوم.');
        }
    }
}
