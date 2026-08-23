<?php

namespace App\Support;

use Illuminate\Support\Str;

final class BusinessReference
{
    /** Generate a readable reference without the max(id)+1 concurrency race. */
    public static function make(string $prefix, bool $withDate = true, ?string $suffix = null): string
    {
        $parts = [$prefix];
        if ($withDate) {
            $parts[] = now()->format('ymd');
        }
        $parts[] = substr((string) Str::ulid(), -10);
        if ($suffix !== null && $suffix !== '') {
            $parts[] = $suffix;
        }

        return implode('-', $parts);
    }
}
