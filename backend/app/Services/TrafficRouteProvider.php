<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TrafficRouteProvider
{
    /** @return array{minutes:int,distance_km:float,provider:string}|null */
    public function estimate(float $lat1, float $lng1, float $lat2, float $lng2, ?CarbonInterface $at = null): ?array
    {
        if (config('darak.routing.provider') !== 'mapbox' || blank(config('darak.routing.mapbox_token'))) {
            return null;
        }
        $key = 'route:'.implode(':', array_map(fn ($v) => round((float) $v, 4), [$lat1, $lng1, $lat2, $lng2])).':'.($at ?? now())->format('YmdHi');

        return Cache::remember($key, now()->addMinutes(5), function () use ($lat1, $lng1, $lat2, $lng2): ?array {
            try {
                $coordinates = "{$lng1},{$lat1};{$lng2},{$lat2}";
                $response = Http::timeout(4)->retry(1, 150)->get("https://api.mapbox.com/directions/v5/mapbox/driving-traffic/{$coordinates}", [
                    'access_token' => config('darak.routing.mapbox_token'), 'overview' => 'false', 'alternatives' => 'false',
                ]);
                if (! $response->successful() || ! is_array($response->json('routes.0'))) {
                    return null;
                }

                return [
                    'minutes' => max(1, (int) ceil((float) $response->json('routes.0.duration') / 60)),
                    'distance_km' => round((float) $response->json('routes.0.distance') / 1000, 2),
                    'provider' => 'mapbox-traffic',
                ];
            } catch (Throwable) {
                return null;
            }
        });
    }
}
