<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * People-facing labels for stored codes, from the one map in
 * resources/lang/ar/labels.php. The interface is Arabic-first (the English UI
 * translates the Arabic through resources/lang/en/ui.php), so the labels are
 * always read in Arabic.
 *
 * In Blade: {{ \App\Support\Labels::of('visit_state', $visit->state) }}.
 */
final class Labels
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $map = null;

    /** The label of $code in $group; an unmapped code is shown as is, never hidden. */
    public static function of(string $group, ?string $code, string $empty = '—'): string
    {
        if ($code === null || $code === '') {
            return $empty;
        }

        return self::group($group)[$code] ?? $code;
    }

    /** Several codes (e.g. a technician's specialties) as one line. */
    public static function list(string $group, ?array $codes, string $separator = ' · ', string $empty = '—'): string
    {
        $labels = array_map(fn ($code) => self::of($group, (string) $code), array_values(array_filter($codes ?? [])));

        return $labels === [] ? $empty : implode($separator, $labels);
    }

    /** A visit timeline event, including the state machine's `state.<from>.to.<to>`. */
    public static function visitEvent(?string $type): string
    {
        if ($type !== null && preg_match('/^state\.([a-z_]+)\.to\.([a-z_]+)$/', $type, $match) === 1) {
            return 'تغيير الحالة: '.self::of('visit_state', $match[1]).' ← '.self::of('visit_state', $match[2]);
        }

        return self::of('visit_event', $type);
    }

    /** @return array<string, string> code => label, for selects and checkbox lists */
    public static function group(string $group): array
    {
        self::$map ??= (array) Lang::get('labels', [], 'ar');

        return self::$map[$group] ?? [];
    }
}
