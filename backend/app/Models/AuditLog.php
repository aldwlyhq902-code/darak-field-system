<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id', 'action', 'auditable_type', 'auditable_id',
        'before', 'after', 'ip', 'user_agent', 'previous_hash', 'entry_hash',
        'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('سجل التدقيق غير قابل للتعديل.'));
        static::deleting(fn () => throw new \RuntimeException('سجل التدقيق غير قابل للحذف.'));
    }
}
