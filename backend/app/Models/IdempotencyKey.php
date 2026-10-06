<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A remembered response for one (user, Idempotency-Key). Kept for
 * darak.api.idempotency_ttl_hours (48 h by default), then pruned by
 * `php artisan model:prune --model="App\Models\IdempotencyKey"` or
 * IdempotencyKey::pruneExpired().
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    public const STATE_PROCESSING = 'processing';

    public const STATE_COMPLETED = 'completed';

    protected $fillable = [
        'user_id', 'idempotency_key', 'method', 'path', 'request_hash', 'state',
        'response_status', 'response_body', 'response_content_type', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }

    /** For a scheduler that prefers a plain call to model:prune. */
    public static function pruneExpired(): int
    {
        return (int) static::query()->where('expires_at', '<', now())->delete();
    }
}
