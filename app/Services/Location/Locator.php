<?php

namespace App\Services\Location;

use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Turns a point on the map into an address (spec ch 20, "Automatic location"). The buyer's phone gives two numbers; the connected map service
 * (OpenStreetMap or Google, chosen in Connections) names the place; we match its district to our own list and leave the rest for the buyer to edit.
 * The service is asked from the server so the key never reaches a browser, answers are kept a day by rounded point, and nothing the service writes
 * is trusted beyond a short, cleaned piece of text.
 */
class Locator
{
    public function __construct(private Integrations $integrations) {}

    public function enabled(): bool
    {
        return $this->integrations->activeOfType('map') !== null;
    }

    /** Bangladesh only: a point outside it is a wrong or faked reading. */
    public static function inBangladesh(float $lat, float $lng): bool
    {
        return $lat >= 20.5 && $lat <= 26.7 && $lng >= 88.0 && $lng <= 92.8;
    }

    /**
     * @return array{district: ?string, area: string, line: string}
     *
     * @throws LocationException
     */
    public function reverse(float $lat, float $lng): array
    {
        if (! self::inBangladesh($lat, $lng)) {
            throw new LocationException('This place is outside Bangladesh.');
        }

        $on = $this->integrations->activeOfType('map');

        if (! $on) {
            throw new LocationException('Finding your place is not switched on.');
        }

        [$key, $cfg] = $on;
        $cacheKey = 'geo:'.$key.':'.round($lat, 4).':'.round($lng, 4);

        $hit = Cache::get($cacheKey);

        if (is_array($hit)) {
            return $hit;
        }

        $found = $key === 'map:google' ? $this->google($cfg, $lat, $lng) : $this->nominatim($cfg, $lat, $lng);
        Cache::put($cacheKey, $found, 86400);

        return $found;
    }

    /**
     * The test button: ask about a known place (central Dhaka) and say whether the answer made sense.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{ok: bool, note: string}
     */
    public function probe(string $key, array $cfg): array
    {
        try {
            $r = $key === 'map:google' ? $this->google($cfg, 23.7806, 90.4075) : $this->nominatim($cfg, 23.7806, 90.4075);
        } catch (LocationException $e) {
            return ['ok' => false, 'note' => __($e->getMessage())];
        }

        return $r['district'] === 'Dhaka' ? ['ok' => true, 'note' => __('It found Dhaka.')] : ['ok' => false, 'note' => __('It answered, but did not find Dhaka. Check the address of the service.')];
    }

    /** @param array<string, mixed> $cfg */
    private function nominatim(array $cfg, float $lat, float $lng): array
    {
        $base = rtrim((string) ($cfg['settings']['base_url'] ?? 'https://nominatim.openstreetmap.org'), '/');
        $mail = (string) ($cfg['settings']['contact_email'] ?? '');

        if (! SafeUrl::ok($base)) {
            throw new LocationException('The map service address is not safe to use.');
        }

        $j = $this->get($base.'/reverse', ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 18, 'addressdetails' => 1, 'accept-language' => 'en', 'email' => $mail], ['User-Agent' => 'SiamsAqua/1.0 ('.$mail.')']);
        $a = (array) ($j['address'] ?? []);

        if ($a === []) {
            throw new LocationException('We could not find an address at this place.');
        }

        return [
            'district' => $this->district([(string) ($a['state_district'] ?? ''), (string) ($a['county'] ?? ''), (string) ($a['city'] ?? ''), (string) ($a['state'] ?? '')]),
            'area' => $this->pick($a, ['suburb', 'neighbourhood', 'city_district', 'quarter', 'village', 'town', 'municipality', 'city']),
            'line' => $this->pick($a, ['road', 'pedestrian', 'residential', 'hamlet']),
        ];
    }

    /** @param array<string, mixed> $cfg */
    private function google(array $cfg, float $lat, float $lng): array
    {
        $j = $this->get('https://maps.googleapis.com/maps/api/geocode/json', ['latlng' => $lat.','.$lng, 'language' => 'en', 'region' => 'bd', 'key' => (string) ($cfg['credentials']['api_key'] ?? '')], []);

        if (($j['status'] ?? '') !== 'OK' || empty($j['results'][0]['address_components'])) {
            throw new LocationException('We could not find an address at this place.');
        }

        $by = [];

        foreach ($j['results'][0]['address_components'] as $c) {
            foreach ((array) ($c['types'] ?? []) as $type) {
                $by[$type] ??= (string) ($c['long_name'] ?? '');
            }
        }

        return [
            'district' => $this->district([$by['administrative_area_level_2'] ?? '', $by['administrative_area_level_1'] ?? '', $by['locality'] ?? '']),
            'area' => $this->clean($by['sublocality_level_1'] ?? $by['sublocality'] ?? $by['neighborhood'] ?? $by['locality'] ?? ''),
            'line' => $this->clean(trim(($by['street_number'] ?? '').' '.($by['route'] ?? ''))),
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function get(string $url, array $query, array $headers): array
    {
        try {
            $r = Http::timeout(6)->withHeaders($headers)->get($url, $query);
        } catch (ConnectionException) {
            throw new LocationException('Could not reach the map service. Please type your address.');
        }

        if (! $r->successful() || ! is_array($r->json())) {
            throw new LocationException('The map service did not answer. Please type your address.');
        }

        return $r->json();
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  list<string>  $keys
     */
    private function pick(array $a, array $keys): string
    {
        foreach ($keys as $k) {
            if (! empty($a[$k]) && is_string($a[$k])) {
                return $this->clean($a[$k]);
            }
        }

        return '';
    }

    /** Short plain text only: no markup, no control characters. */
    private function clean(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[\p{C}<>]+/u', ' ', strip_tags($s))), 0, 80);
    }

    /**
     * The first of the names that is one of our districts, once words like "District" are dropped.
     *
     * @param  list<string>  $names
     */
    private function district(array $names): ?string
    {
        $ours = config('bd.districts');
        $alias = ['comilla' => 'Cumilla', 'chittagong' => 'Chattogram', 'barisal' => 'Barishal', 'bogra' => 'Bogura', 'jessore' => 'Jashore', 'jhalakathi' => 'Jhalokathi', 'nawabganj' => 'Chapainawabganj', 'coxs bazar' => "Cox's Bazar", "cox's bazar" => "Cox's Bazar", 'narayangonj' => 'Narayanganj'];

        foreach ($names as $n) {
            $n = mb_strtolower(trim((string) preg_replace('/\b(district|zila|zilla|division|city corporation|sadar)\b/i', '', $n)));

            if ($n === '') {
                continue;
            }

            if (isset($alias[$n])) {
                return in_array($alias[$n], $ours, true) ? $alias[$n] : null;
            }

            foreach ($ours as $d) {
                if (mb_strtolower($d) === $n) {
                    return $d;
                }
            }
        }

        return null;
    }
}
