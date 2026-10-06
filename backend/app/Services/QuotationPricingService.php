<?php

namespace App\Services;

use App\Models\FinancialApproval;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Price control for quotations written by anyone other than the owner.
 *
 * A non-owner may quote at or near the package list price freely. Below the
 * configured floor, below the list price by more than the allowed discount, or
 * for a package with no list price at all, the exact quoted amount must pass
 * the same dual financial approval used for discounts before the quotation
 * can reach the client. Revisions are new quotation rows, so an approval never
 * carries over to a changed price.
 */
class QuotationPricingService
{
    public const ACTION = 'price';

    /** Why this quotation's price needs dual approval, or null when it does not. */
    public function approvalReason(Quotation $quotation): ?string
    {
        // Renewal offers carry no author (the signed contract price); the owner
        // sets prices and is never gated.
        $author = $quotation->created_by ? User::query()->find($quotation->created_by) : null;
        if ($author === null || $author->isOwner() || $author->isPlatformAdmin()) {
            return null;
        }

        $price = round((float) $quotation->price_amount, 2);
        $floor = $this->number(config('darak.quotation_pricing.price_floor'));
        if ($floor !== null && $price < $floor) {
            return 'السعر أقل من الحد الأدنى المعتمد للتسعير.';
        }

        $list = $this->number(config('darak.quotation_pricing.list_prices.'.$quotation->package_code));
        if ($list === null) {
            return 'لا يوجد سعر قائمة معتمد لهذه الباقة.';
        }
        $discount = min(100.0, max(0.0, $this->number(config('darak.quotation_pricing.max_discount_percent')) ?? 0.0));
        if ($price < round($list * (1 - $discount / 100), 2)) {
            return 'السعر أقل من سعر القائمة بأكثر من الخصم المسموح.';
        }

        return null;
    }

    /** An approval covers exactly the amount that was approved, nothing lower. */
    public function isApproved(Quotation $quotation): bool
    {
        return FinancialApproval::query()
            ->where('subject_type', 'quotation')->where('subject_id', $quotation->id)
            ->where('action_type', self::ACTION)->where('status', 'approved')
            ->pluck('amount')
            ->contains(fn ($amount) => bccomp((string) $amount, (string) $quotation->price_amount, 2) === 0);
    }

    /** Opens the price approval a freshly written quotation will need, if any. */
    public function requestIfNeeded(Quotation $quotation, int $actorId): ?FinancialApproval
    {
        $reason = $this->approvalReason($quotation);
        if ($reason === null) {
            return null;
        }

        return FinancialApproval::create([
            'public_reference' => (string) Str::uuid(), 'action_type' => self::ACTION,
            'subject_type' => 'quotation', 'subject_id' => $quotation->id,
            'amount' => $quotation->price_amount, 'reason' => $reason.' (العرض '.$quotation->quote_no.')',
            'status' => 'pending_first', 'requested_by' => $actorId,
        ]);
    }

    /** The owner's own send is the approval; everyone else waits for the dual one. */
    public function assertSendable(Quotation $quotation, User $actor): void
    {
        if ($actor->isOwner() || $actor->isPlatformAdmin()) {
            return;
        }
        $reason = $this->approvalReason($quotation);
        if ($reason !== null && ! $this->isApproved($quotation)) {
            throw new RuntimeException('لا يمكن إرسال العرض قبل الاعتماد المالي الثنائي لسعره: '.$reason);
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
