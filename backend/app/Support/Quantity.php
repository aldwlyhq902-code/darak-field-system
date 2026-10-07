<?php

namespace App\Support;

/**
 * Quantities as people write them: 1, 1.5, 2.25 — not "1.000". Stored with
 * three decimals (decimal casts), shown without trailing zeros, with
 * thousands separators.
 */
final class Quantity
{
    public static function format(int|float|string|null $qty, int $decimals = 3): string
    {
        if ($qty === null || $qty === '') {
            return '—';
        }

        $formatted = number_format((float) $qty, $decimals, '.', ',');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }
}
