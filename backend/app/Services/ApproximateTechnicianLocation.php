<?php

namespace App\Services;

use App\Models\Visit;

/**
 * What the client portal may learn about where the technician is.
 *
 * Only while the visit is en route, only to about 100 m (three decimals), and
 * nothing once the technician has arrived. The full-precision position stays
 * on the server for ETA and dispatch.
 *
 * The map is drawn from plain XYZ tiles (no third-party script or iframe), so
 * the tile server learns only the approximate area and the viewer's IP.
 * DARAK_MAP_TILES_URL=none removes the map entirely: text-only ETA and no
 * request leaves the platform.
 */
class ApproximateTechnicianLocation
{
    public const DECIMALS = 3;

    private const ZOOM = 15;

    /**
     * @return array{lat: float, lng: float, updated_at: mixed, tiles: array<int, string>, marker_left: float, marker_top: float, attribution: string}|null
     */
    public function forClient(Visit $visit): ?array
    {
        if ($visit->state !== Visit::STATE_EN_ROUTE || $visit->technician_lat === null || $visit->technician_lng === null) {
            return null;
        }

        $lat = round((float) $visit->technician_lat, self::DECIMALS);
        $lng = round((float) $visit->technician_lng, self::DECIMALS);
        $location = [
            'lat' => $lat,
            'lng' => $lng,
            'updated_at' => $visit->location_updated_at,
            'tiles' => [],
            'marker_left' => 50.0,
            'marker_top' => 50.0,
            'attribution' => (string) config('darak.map_attribution', ''),
        ];

        $template = self::tileTemplate();

        if ($template === null) {
            return $location;
        }

        // Slippy-map tile coordinates; a 3×3 grid centred on the tile holding the point.
        $scale = 2 ** self::ZOOM;
        $x = ($lng + 180) / 360 * $scale;
        $y = (1 - asinh(tan(deg2rad($lat))) / M_PI) / 2 * $scale;
        $originX = (int) floor($x) - 1;
        $originY = (int) floor($y) - 1;

        for ($row = 0; $row < 3; $row++) {
            for ($column = 0; $column < 3; $column++) {
                $location['tiles'][] = strtr($template, [
                    '{z}' => (string) self::ZOOM,
                    '{x}' => (string) (($originX + $column) % $scale),
                    '{y}' => (string) ($originY + $row),
                    '{s}' => 'a',
                ]);
            }
        }

        $location['marker_left'] = round(($x - $originX) / 3 * 100, 2);
        $location['marker_top'] = round(($y - $originY) / 3 * 100, 2);

        return $location;
    }

    /** The configured XYZ template, or null for "none" or anything unsafe. */
    public static function tileTemplate(): ?string
    {
        $template = trim((string) config('darak.map_tiles_url', ''));

        if ($template === '' || strtolower($template) === 'none' || ! str_starts_with($template, 'https://')) {
            return null;
        }

        foreach (['{z}', '{x}', '{y}'] as $placeholder) {
            if (! str_contains($template, $placeholder)) {
                return null;
            }
        }

        return $template;
    }

    /** Origin to allow in the CSP img-src, or null when no map is drawn. */
    public static function tileOrigin(): ?string
    {
        $template = self::tileTemplate();

        if ($template === null) {
            return null;
        }

        $parts = parse_url(str_replace(['{s}', '{z}', '{x}', '{y}'], ['a', '0', '0', '0'], $template));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
