<?php

namespace App\Services\Couriers;

use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Steadfast Courier (https://portal.steadfast.com.bd/api/v1): the key pair goes in the Api-Key and Secret-Key headers; POST /create_order books a
 * parcel; GET /status_by_cid/{id} says where it is; GET /get_balance is a harmless call to prove the login. The keys come from the Connections screen.
 */
class SteadfastProvider implements CourierProvider
{
    private const BASE = 'https://portal.steadfast.com.bd/api/v1';

    public function __construct(private Integrations $integrations) {}

    public function key(): string
    {
        return 'steadfast';
    }

    public function book(array $parcel): array
    {
        $json = $this->answer(fn () => $this->request()->post($this->url('/create_order'), [
            'invoice' => $parcel['invoice'], 'recipient_name' => $parcel['name'], 'recipient_phone' => $parcel['phone'],
            'recipient_address' => $parcel['address'], 'cod_amount' => round($parcel['cod'], 2),
        ]));
        $c = (array) ($json['consignment'] ?? []);

        if (($json['status'] ?? null) != 200 || empty($c['consignment_id'])) {
            throw new CourierException('Steadfast did not take the parcel. Check the address and phone, then try again.');
        }

        return ['consignment_id' => (string) $c['consignment_id'], 'tracking_code' => isset($c['tracking_code']) ? (string) $c['tracking_code'] : null, 'status' => (string) ($c['status'] ?? 'in_review')];
    }

    public function status(string $consignmentId): string
    {
        $json = $this->answer(fn () => $this->request()->get($this->url('/status_by_cid/'.rawurlencode($consignmentId))));

        return (string) ($json['delivery_status'] ?? 'unknown');
    }

    public function check(): void
    {
        $json = $this->answer(fn () => $this->request()->get($this->url('/get_balance')));

        if (($json['status'] ?? null) != 200) {
            throw new CourierException('Steadfast did not accept these keys.');
        }
    }

    // ------------------------------------------------------------------ helpers

    private function values(): array
    {
        $on = $this->integrations->active('courier:steadfast') ?? throw new CourierException('Steadfast is not switched on.');

        return $on;
    }

    private function url(string $path): string
    {
        $base = rtrim((string) ($this->values()['settings']['base_url'] ?? self::BASE), '/');

        if (! SafeUrl::ok($base)) {
            throw new CourierException('The Steadfast address must be https and a public website.');
        }

        return $base.$path;
    }

    private function request(): PendingRequest
    {
        $c = $this->values()['credentials'];

        if (empty($c['api_key']) || empty($c['secret_key'])) {
            throw new CourierException('The Steadfast keys are not saved yet.');
        }

        return Http::timeout(15)->withoutRedirecting()->acceptJson()->asJson()->withHeaders(['Api-Key' => $c['api_key'], 'Secret-Key' => $c['secret_key']]);
    }

    /** The answer as an array, or a clear short failure. A connection problem is not told as "they refused". */
    private function answer(\Closure $send): array
    {
        try {
            /** @var Response $res */
            $res = $send();
        } catch (ConnectionException) {
            throw new CourierException('Could not reach Steadfast.');
        }

        if ($res->status() === 401 || $res->status() === 403) {
            throw new CourierException('Steadfast did not accept these keys.');
        }

        if (! $res->successful()) {
            throw new CourierException('Steadfast did not take the request.');
        }

        return (array) $res->json();
    }
}
