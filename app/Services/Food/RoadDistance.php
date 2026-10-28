<?php

namespace App\Services\Food;

use App\Models\Setting;
use App\Services\Integrations\Integrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * How far apart two places are by road (spec ch 17: by road, because rivers and railways make a straight line wrong). When Google Maps is switched on in
 * Connections, the road distance comes from Google's Distance Matrix API, asked from the server so the key never reaches a browser, and kept a week by rounded
 * pair of points. When it is not on, or Google does not answer, the straight line over the earth (the haversine formula) times a road allowance the owner sets
 * stands in, and the answer says it is an estimate so the screens can say so too.
 */
class RoadDistance
{
    private const EARTH_KM = 6371.0088;

    public function __construct(private Integrations $integrations) {}

    /** Is the real road distance on? */
    public function viaGoogle(): bool
    {
        return $this->integrations->active('map:google') !== null;
    }

    /**
     * @return array{km: float, by_road: bool}
     */
    public function between(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $cfg = $this->integrations->active('map:google');

        if ($cfg !== null) {
            $key = 'road:'.round($lat1, 4).','.round($lng1, 4).':'.round($lat2, 4).','.round($lng2, 4);
            $km = Cache::get($key);

            if (! is_numeric($km)) {
                $km = $this->google((string) ($cfg['credentials']['api_key'] ?? ''), $lat1, $lng1, $lat2, $lng2);

                if ($km !== null) {
                    Cache::put($key, $km, 7 * 86400);
                }
            }

            if (is_numeric($km)) {
                return ['km' => round((float) $km, 2), 'by_road' => true];
            }
        }

        return ['km' => self::estimate($lat1, $lng1, $lat2, $lng2), 'by_road' => false];
    }

    /**
     * The test button for Google Maps: two known places in Dhaka, about four kilometres apart by road.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{ok: bool, note: string}
     */
    public function probe(array $cfg): array
    {
        $key = (string) ($cfg['credentials']['api_key'] ?? '');
        [$status, $km, $message] = $this->ask($key, 23.7806, 90.4075, 23.7461, 90.3742);

        if ($status === 'OK' && $km !== null && $km > 1 && $km < 30) {
            return ['ok' => true, 'note' => __('Road distance works (about {km} km across Dhaka).', ['km' => number_format($km, 1)])];
        }

        return ['ok' => false, 'note' => __('Road distance did not work: {why}. Turn on the Distance Matrix API for this key.', ['why' => $message ?: $status ?: __('no answer')])];
    }

    /** Straight line over the earth, in kilometres. */
    public static function straight(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_KM * asin(min(1.0, sqrt($a)));
    }

    public static function factor(): float
    {
        return min(3.0, max(1.0, (float) Setting::read('food.road_factor', 1.3)));
    }

    /** The straight line times the road allowance: what stands in when there is no real road distance. */
    public static function estimate(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return round(self::straight($lat1, $lng1, $lat2, $lng2) * self::factor(), 2);
    }

    private function google(string $key, float $lat1, float $lng1, float $lat2, float $lng2): ?float
    {
        [$status, $km] = $this->ask($key, $lat1, $lng1, $lat2, $lng2);

        return $status === 'OK' ? $km : null;
    }

    /**
     * @return array{0: ?string, 1: ?float, 2: ?string} the status Google gave, the kilometres, and what it said went wrong
     */
    private function ask(string $key, float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        if ($key === '') {
            return [null, null, __('there is no key')];
        }

        try {
            $r = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => $lat1.','.$lng1, 'destinations' => $lat2.','.$lng2, 'mode' => 'driving', 'units' => 'metric', 'region' => 'bd', 'key' => $key,
            ]);
        } catch (ConnectionException) {
            return [null, null, __('could not reach Google')];
        }

        $j = $r->json();

        if (! is_array($j)) {
            return [null, null, __('Google did not answer')];
        }

        $element = $j['rows'][0]['elements'][0] ?? [];
        $meters = $element['distance']['value'] ?? null;

        if (($j['status'] ?? '') !== 'OK' || ($element['status'] ?? '') !== 'OK' || ! is_numeric($meters)) {
            return [(string) ($element['status'] ?? $j['status'] ?? ''), null, mb_substr(trim(strip_tags((string) ($j['error_message'] ?? ''))), 0, 160) ?: null];
        }

        return ['OK', round(((float) $meters) / 1000, 2), null];
    }
}
