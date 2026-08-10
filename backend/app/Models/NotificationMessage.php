<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationMessage extends Model
{
    use HasFactory;

    public const TYPE_VISIT_ASSIGNED = 'visit.assigned';
    public const TYPE_SLA_AT_RISK = 'sla.at_risk';
    public const TYPE_REPORT_READY = 'report.ready';

    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_WHATSAPP_MANUAL = 'whatsapp_manual';
    public const CHANNEL_MAIL = 'mail';

    protected $fillable = [
        'type', 'channel', 'recipient_kind', 'user_id', 'phone', 'visit_id',
        'subject', 'body', 'context', 'status', 'attempts', 'available_at',
        'sent_at', 'last_error', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * Pre-composed wa.me link the supervisor taps to send.
     *
     * WhatsApp Business API requires templates to be approved in advance, which
     * cannot happen before the business is registered with a provider. Until then
     * the honest implementation is a prepared message a human sends — not a queue
     * that pretends to deliver.
     */
    public function whatsappLink(): ?string
    {
        if (blank($this->phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $this->phone);

        if ($digits === '') {
            return null;
        }

        // Saudi local format 05xxxxxxxx -> 9665xxxxxxxx
        if (str_starts_with($digits, '0')) {
            $digits = '966' . substr($digits, 1);
        }

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($this->body);
    }
}
