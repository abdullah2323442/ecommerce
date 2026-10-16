<?php

namespace App\Services\Couriers;

use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pathao Courier merchant API (/aladdin/api/v1). A login (client id and secret, the merchant's email and password) gives an access token that is kept
 * until shortly before it ends; a parcel needs the numbers of Pathao's own city and zone, which we look up from their lists and match to the buyer's
 * district and area, and which staff can correct before booking. POST /orders books; GET /orders/{id}/info says where it is; GET /stores proves the
 * login and the store number. The keys come from the Connections screen and are never written to a log.
 */
class PathaoProvider implements CourierProvider
{
    private const BASE = 'https://api-hermes.pathao.com';

    /** Our district names that Pathao writes the old way. */
    private const CITY_ALIAS = ['chattogram' => 'chittagong', 'cumilla' => 'comilla', 'barishal' => 'barisal', 'bogura' => 'bogra', 'jashore' => 'jessore', 'coxsbazar' => 'coxsbazar', 'jhalokathi' => 'jhalakathi', 'chapainawabganj' => 'nawabganj'];

    public function __construct(private Integrations $integrations) {}

    public function key(): string
    {
        return 'pathao';
    }

    public function book(array $parcel): array
    {
        $name = trim((string) $parcel['name']);
        $address = trim((string) $parcel['address']);

        if (mb_strlen($name) < 3 || mb_strlen($address) < 10 || ! preg_match('/^01[3-9]\d{8}$/', (string) $parcel['phone'])) {
            throw new CourierException('Pathao needs the buyer\'s name (3 letters or more), an 11-digit mobile number and an address of at least 10 letters.');
        }

        $city = (int) ($parcel['city_id'] ?? 0);
        $zone = (int) ($parcel['zone_id'] ?? 0);

        if ($city < 1 || $zone < 1) {
            $guess = $this->match((string) ($parcel['district'] ?? ''), (string) ($parcel['area'] ?? ''));
            $city = (int) ($guess['city_id'] ?? 0);
            $zone = (int) ($guess['zone_id'] ?? 0);
        }

        if ($city < 1 || $zone < 1) {
            throw new CourierException('Choose the city and zone for Pathao, then book again.');
        }

        $json = $this->call(fn (PendingRequest $r) => $r->post($this->url('/aladdin/api/v1/orders'), [
            'store_id' => (int) $this->setting('store_id'), 'merchant_order_id' => $parcel['invoice'], 'recipient_name' => mb_substr($name, 0, 100), 'recipient_phone' => $parcel['phone'],
            'recipient_address' => mb_substr($address, 0, 220), 'recipient_city' => $city, 'recipient_zone' => $zone, 'delivery_type' => 48, 'item_type' => 2, 'item_quantity' => 1,
            'item_weight' => max(0.5, min(10.0, round((float) ($parcel['weight'] ?? 0.5), 1))), 'amount_to_collect' => (int) round((float) $parcel['cod']),
            'special_instruction' => mb_substr((string) ($parcel['note'] ?? ''), 0, 200), 'item_description' => 'Order '.$parcel['invoice'],
        ]));
        $d = (array) ($json['data'] ?? []);

        if (empty($d['consignment_id'])) {
            throw new CourierException('Pathao did not take the parcel. Check the address and phone, then try again.');
        }

        return ['consignment_id' => (string) $d['consignment_id'], 'tracking_code' => (string) $d['consignment_id'], 'status' => self::normalise((string) ($d['order_status'] ?? 'Pending'))];
    }

    public function status(string $consignmentId): string
    {
        $json = $this->call(fn (PendingRequest $r) => $r->get($this->url('/aladdin/api/v1/orders/'.rawurlencode($consignmentId).'/info')));

        return self::normalise((string) ($json['data']['order_status'] ?? ''));
    }

    public function check(): void
    {
        $stores = collect($this->stores());
        $mine = (int) $this->setting('store_id');

        if ($stores->isEmpty()) {
            throw new CourierException('Pathao accepted the login, but this merchant has no store yet.');
        }

        if (! $stores->contains(fn ($s) => (int) ($s['store_id'] ?? 0) === $mine)) {
            throw new CourierException('Pathao accepted the login, but the Store ID is not one of your stores.');
        }
    }

    // ------------------------------------------------------------------ their places

    /** @return list<array{id: int, name: string}> */
    public function cities(): array
    {
        return Cache::remember('pathao:cities', 86400, fn () => $this->list('/aladdin/api/v1/city-list', 'city_id', 'city_name'));
    }

    /** @return list<array{id: int, name: string}> */
    public function zones(int $city): array
    {
        return Cache::remember('pathao:zones:'.$city, 86400, fn () => $this->list('/aladdin/api/v1/cities/'.$city.'/zone-list', 'zone_id', 'zone_name'));
    }

    /**
     * Pathao's city and zone for a district and an area, when they can be told apart with confidence; a missing zone is left for staff to choose.
     *
     * @return array{city_id: int, zone_id: ?int}|null
     */
    public function match(string $district, string $area): ?array
    {
        $want = self::flat($district);
        $want = self::CITY_ALIAS[$want] ?? $want;
        $city = collect($this->cities())->first(fn ($c) => self::flat($c['name']) === $want);

        if (! $city) {
            return null;
        }

        $a = self::flat($area);
        $zone = $a === '' ? null : collect($this->zones($city['id']))->first(fn ($z) => ($f = self::flat($z['name'])) !== '' && ($f === $a || str_contains($a, $f) || str_contains($f, $a)));

        return ['city_id' => $city['id'], 'zone_id' => $zone['id'] ?? null];
    }

    /** Pathao's word for where the parcel is → the words this site uses everywhere (see CourierBooking::STATUS_LABELS). */
    public static function normalise(string $word): string
    {
        $w = self::flat($word);

        return match (true) {
            $w === 'delivered' || str_starts_with($w, 'delivered') && ! str_contains($w, 'partial') => 'delivered',
            str_contains($w, 'partial') => 'partial_delivered',
            str_contains($w, 'return') || str_contains($w, 'cancel') => 'cancelled',
            str_contains($w, 'hold') || str_contains($w, 'fail') => 'hold',
            $w === '' => 'unknown',
            default => 'pending',
        };
    }

    // ------------------------------------------------------------------ helpers

    private static function flat(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? '';
    }

    /** @return list<array<string, mixed>> */
    private function stores(): array
    {
        $json = $this->call(fn (PendingRequest $r) => $r->get($this->url('/aladdin/api/v1/stores')));

        return (array) ($json['data']['data'] ?? $json['data'] ?? []);
    }

    /** @return list<array{id: int, name: string}> */
    private function list(string $path, string $idKey, string $nameKey): array
    {
        $json = $this->call(fn (PendingRequest $r) => $r->get($this->url($path)));
        $rows = (array) ($json['data']['data'] ?? $json['data'] ?? []);

        return collect($rows)->filter(fn ($r) => isset($r[$idKey], $r[$nameKey]))->map(fn ($r) => ['id' => (int) $r[$idKey], 'name' => mb_substr((string) $r[$nameKey], 0, 80)])->values()->all();
    }

    private function values(): array
    {
        return $this->integrations->active('courier:pathao') ?? throw new CourierException('Pathao is not switched on.');
    }

    private function setting(string $name): string
    {
        $v = $this->values();

        return (string) ($v['credentials'][$name] ?? $v['settings'][$name] ?? '');
    }

    private function url(string $path): string
    {
        $base = rtrim((string) ($this->values()['settings']['base_url'] ?? self::BASE), '/') ?: self::BASE;

        if (! SafeUrl::ok($base)) {
            throw new CourierException('The Pathao address must be https and a public website.');
        }

        return $base.$path;
    }

    /** The access token, asked for only when we have none or it has ended. */
    private function token(bool $fresh = false): string
    {
        foreach (['client_id', 'client_secret', 'username', 'password'] as $k) {
            if ($this->setting($k) === '') {
                throw new CourierException('The Pathao keys are not saved yet.');
            }
        }

        $cache = 'pathao:token:'.sha1($this->setting('client_id').'|'.$this->setting('username'));

        if ($fresh) {
            Cache::forget($cache);
        }

        if ($hit = Cache::get($cache)) {
            return (string) $hit;
        }

        try {
            $res = Http::timeout(15)->withoutRedirecting()->acceptJson()->asJson()->post($this->url('/aladdin/api/v1/issue-token'), [
                'client_id' => $this->setting('client_id'), 'client_secret' => $this->setting('client_secret'), 'username' => $this->setting('username'), 'password' => $this->setting('password'), 'grant_type' => 'password',
            ]);
        } catch (ConnectionException) {
            throw new CourierException('Could not reach Pathao.');
        }

        if ($res->status() >= 400 || empty($res->json('access_token'))) {
            throw new CourierException('Pathao did not accept these keys.');
        }

        $ttl = max(60, min(86400 * 5, (int) $res->json('expires_in', 3600) - 120));
        Cache::put($cache, (string) $res->json('access_token'), $ttl);

        return (string) $res->json('access_token');
    }

    /** One authorised call; if the token has gone stale it is renewed once. @return array<string, mixed> */
    private function call(\Closure $send): array
    {
        $res = $this->once($send, $this->token());

        if ($res->status() === 401) {
            $res = $this->once($send, $this->token(true));
        }

        if ($res->status() === 401 || $res->status() === 403) {
            throw new CourierException('Pathao did not accept these keys.');
        }

        if (! $res->successful()) {
            throw new CourierException('Pathao did not take the request.');
        }

        return (array) $res->json();
    }

    private function once(\Closure $send, string $token): Response
    {
        try {
            return $send(Http::timeout(15)->withoutRedirecting()->acceptJson()->asJson()->withToken($token));
        } catch (ConnectionException) {
            throw new CourierException('Could not reach Pathao.');
        }
    }
}
