<?php

namespace App\Services;

use App\Models\ContractInstallment;
use App\Models\FinancialApproval;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class FinancialApprovalService
{
    public function __construct(private readonly AuditLogger $audit, private readonly QuotationPricingService $pricing) {}

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
                $this->apply($approval, $actorId);
                $approval->forceFill(['status' => 'approved', 'second_approved_by' => $actorId, 'second_approved_at' => now()])->save();
            } else {
                throw new RuntimeException('هذا الطلب لا ينتظر اعتمادًا.');
            }
            $this->audit->record('financial_approval.'.$approval->status, $approval, null, ['actor_id' => $actorId], $actorId);

            return $approval;
        });
    }

    /**
     * A discount may only change an offer still open to the client: a draft, or
     * one sent and awaiting a reply. Never one already accepted, converted to a
     * contract, rejected, superseded by a newer version or past its validity.
     */
    public function assertQuotationDiscountable(Quotation $quotation): void
    {
        $open = in_array($quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)
            && $quotation->converted_contract_id === null
            && $quotation->superseded_at === null
            && ! ($quotation->valid_until !== null && $quotation->valid_until->copy()->endOfDay()->isPast());
        if (! $open) {
            throw new RuntimeException('لا يمكن تطبيق الخصم: الخصم مسموح فقط على عرض مسودة أو مرسل بانتظار رد العميل، وليس على عرض معتمد أو محوّل إلى عقد أو مرفوض أو مستبدل أو منتهي الصلاحية.');
        }
    }

    private function apply(FinancialApproval $approval, int $actorId): void
    {
        $amount = (float) $approval->amount;
        if ($approval->subject_type === 'quotation' && $approval->action_type === 'discount') {
            $quotation = Quotation::lockForUpdate()->findOrFail($approval->subject_id);
            $this->assertQuotationDiscountable($quotation);
            if ($amount <= 0 || $amount >= (float) $quotation->price_amount) {
                throw new RuntimeException('قيمة الخصم غير صالحة.');
            }
            // Judged before the price moves: a dual-approved discount extends a
            // price that was acceptable or approved; it never launders one that was not.
            $baseCleared = $this->pricing->approvalReason($quotation) === null || $this->pricing->isApproved($quotation);
            $quotation->decrement('price_amount', $amount);
            if ($baseCleared && $this->pricing->approvalReason($quotation->refresh()) !== null) {
                FinancialApproval::create([
                    'public_reference' => (string) Str::uuid(), 'action_type' => QuotationPricingService::ACTION,
                    'subject_type' => 'quotation', 'subject_id' => $quotation->id, 'amount' => $quotation->price_amount,
                    'reason' => 'سعر ناتج عن خصم معتمد '.$approval->public_reference, 'status' => 'approved',
                    'requested_by' => $approval->requested_by,
                    'first_approved_by' => $approval->first_approved_by, 'first_approved_at' => $approval->first_approved_at,
                    'second_approved_by' => $actorId, 'second_approved_at' => now(),
                ]);
            }
        } elseif ($approval->subject_type === 'quotation' && $approval->action_type === QuotationPricingService::ACTION) {
            $quotation = Quotation::lockForUpdate()->findOrFail($approval->subject_id);
            if ($quotation->status !== Quotation::STATUS_DRAFT || $quotation->superseded_at !== null) {
                throw new RuntimeException('اعتماد السعر يخص عرضًا مسودة لم يُرسل ولم يُستبدل بإصدار أحدث.');
            }
            if (bccomp((string) $approval->amount, (string) $quotation->price_amount, 2) !== 0) {
                throw new RuntimeException('تغير سعر العرض بعد طلب الاعتماد؛ يلزم طلب اعتماد جديد.');
            }
            // Nothing changes on the quotation: the approved record is what lets it be sent.
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
