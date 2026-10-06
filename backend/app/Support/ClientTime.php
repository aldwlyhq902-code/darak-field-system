<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Normalises timestamps that arrive from outside (phones, API clients, forms)
 * into the application timezone before they are stored.
 *
 * The timestamp columns are naive (no zone) and Laravel writes a Carbon's own
 * wall-clock without converting it. The phone sends UTC ("…Z"), the app runs in
 * Asia/Riyadh, so every device time used to land three hours early — and
 * durations computed against server-side times were off by the same amount.
 * Offset-less input (an HTML datetime-local field) is already app-local and
 * passes through unchanged.
 */
final class ClientTime
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
