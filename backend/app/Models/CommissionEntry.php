<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lifecycle (PRD v1.2): accrued → earned → paid, or reversed inside the
 * clawback window. An `adjustment` row is the negative counterpart of a PAID
 * commission that was clawed back; it only offsets future payouts and is never
 * deducted from wages.
 */
class CommissionEntry extends Model
{
    public const STATUS_ACCRUED = 'accrued';

    public const STATUS_EARNED = 'earned';

    public const STATUS_PAID = 'paid';

    public const STATUS_REVERSED = 'reversed';

    public const TYPE_COMMISSION = 'commission';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const STATUS_LABELS = [
        self::STATUS_ACCRUED => 'مستحقة (فترة الاسترداد)',
        self::STATUS_EARNED => 'مكتسبة',
        self::STATUS_PAID => 'مدفوعة',
        self::STATUS_REVERSED => 'ملغاة',
    ];

    protected $fillable = [
        'commission_rule_id', 'user_id', 'contract_id', 'visit_id', 'payment_id', 'basis_amount',
        'commission_amount', 'status', 'entry_type', 'reverses_entry_id', 'accrued_at', 'earned_at',
        'paid_at', 'reversed_at', 'reversal_reason', 'commission_payout_id', 'offset_amount',
    ];

    protected function casts(): array
    {
        return [
            'basis_amount' => 'decimal:2', 'commission_amount' => 'decimal:2', 'offset_amount' => 'decimal:2',
            'accrued_at' => 'datetime', 'earned_at' => 'datetime', 'paid_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'commission_payout_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function isAdjustment(): bool
    {
        return $this->entry_type === self::TYPE_ADJUSTMENT;
    }

    public function statusLabel(): string
    {
        if ($this->isAdjustment()) {
            return $this->status === self::STATUS_PAID ? 'تسوية سالبة (سُويت)' : 'تسوية سالبة (تُخصم من عمولات قادمة)';
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
