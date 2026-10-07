<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Left-to-right tokens — dates, SKUs, serials, document numbers — inside
 * Arabic text, isolated so the bidi algorithm cannot reorder them.
 *
 * Without isolation "آخر توليد: 2026-10-07" displays as 07-10-2026 (digits
 * after Arabic letters become Arabic numbers, which hyphens do not join) and
 * "(COIL-CLN)" loses its brackets to the wrong side. <bdi dir="ltr"> works
 * in browsers and in mPDF. The value is escaped; use in text, never in an
 * attribute: {{ \App\Support\Ltr::wrap($part->sku) }}.
 */
final class Ltr
{
    public static function wrap(mixed $value): HtmlString
    {
        if ($value === null || $value === '') {
            return new HtmlString('');
        }

        return new HtmlString('<bdi dir="ltr">'.e((string) $value).'</bdi>');
    }

    /**
     * The same isolation as plain text (LEFT-TO-RIGHT ISOLATE … POP DIRECTIONAL
     * ISOLATE), for places that cannot hold markup such as <option>.
     */
    public static function text(mixed $value): string
    {
        return $value === null || $value === '' ? '' : "\u{2066}".$value."\u{2069}";
    }
}
