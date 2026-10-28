<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Couriers\CourierBooking;
use App\Services\Couriers\CourierException;
use App\Services\Food\RoadDistance;
use App\Services\Location\Locator;
use App\Services\Messages\HttpSmsGateway;
use App\Services\Push\Notifications;
use App\Services\Stock\StockException;
use App\Services\Sync\PharmaTrack;
use Illuminate\Support\Facades\Cache;

/**
 * The outside services (text messages, payment, courier, maps, sign-in) connected from the admin panel. The catalogue of services and their fields lives in
 * config/integrations.php. Secrets are encrypted in the database and never sent to a page again: the screen only learns "set" and the last four characters,
 * and leaving a secret box empty keeps the old value. A service whose code is not built yet can have its keys saved but cannot be switched on. Changes are
 * logged by field name only, never by value.
 */
class Integrations
{
    private const CACHE = 'integrations.active';

    public function __construct(private ActivityLogger $activity) {}

    public function provider(string $key): array
    {
        return config("integrations.providers.{$key}") ?? throw new StockException('Unknown service.');
    }

    /** What the admin screen shows: every service, grouped by kind, with secrets masked. @return array<int, array<string, mixed>> */
    public function overview(): array
    {
        $rows = Integration::all()->keyBy('key');

        return collect(config('integrations.types'))->map(function (array $type, string $typeKey) use ($rows) {
            return [
                'type' => $typeKey, 'label' => $type['label'], 'hint' => $type['hint'], 'single' => $type['single'],
                'providers' => collect(config('integrations.providers'))->filter(fn ($p, $k) => str_starts_with($k, $typeKey.':'))->map(fn (array $p, string $key) => $this->present($key, $p, $rows->get($key)))->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(string $key, array $p, ?Integration $row): array
    {
        $creds = (array) ($row?->credentials ?? []);
        $plain = (array) ($row?->settings ?? []);

        return [
            // The address the owner must register with Google or Facebook (it is exact: a different address is refused by them).
            'redirect_uri' => str_starts_with($key, 'social:') ? route('social.callback', substr($key, 7)) : ($key === 'courier:steadfast' ? route('courier.webhook', 'steadfast') : ($key === 'payment:sslcommerz' ? route('payments.ipn', 'sslcommerz') : null)),
            'key' => $key, 'name' => $p['name'], 'help' => $p['help'] ?? null, 'ready' => (bool) $p['ready'], 'enabled' => (bool) $row?->enabled, 'mode' => $row?->mode ?? 'test',
            'tested_at' => $row?->tested_at?->toIso8601String(), 'test_ok' => $row?->test_ok, 'test_note' => $row?->test_note,
            'fields' => collect($p['fields'])->map(function (array $f) use ($creds, $plain) {
                $secret = ! empty($f['secret']);
                $value = $secret ? ($creds[$f['name']] ?? null) : ($plain[$f['name']] ?? ($f['default'] ?? null));

                return [
                    'name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'], 'secret' => $secret, 'required' => ! empty($f['required']), 'help' => $f['help'] ?? null, 'options' => $f['options'] ?? null,
                    // A secret is never sent back: only whether it is there and how it ends.
                    'value' => $secret ? null : $value, 'set' => $secret ? ($value !== null && $value !== '') : null, 'hint' => $secret && $value ? '••••'.mb_substr((string) $value, -4) : null,
                ];
            })->all(),
        ];
    }

    /**
     * Save the fields of one service. A blank secret keeps the old one; a name in `clear` removes it. Turning it on needs the required fields.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $clear
     *
     * @throws StockException
     */
    public function save(string $key, array $input, bool $enabled, string $mode, array $clear, User $by): Integration
    {
        $p = $this->provider($key);
        $row = Integration::firstOrNew(['key' => $key]);
        $creds = (array) ($row->credentials ?? []);
        $plain = (array) ($row->settings ?? []);
        $changed = [];

        foreach ($p['fields'] as $f) {
            $name = $f['name'];
            $given = array_key_exists($name, $input) ? trim((string) $input[$name]) : null;

            if (! empty($f['secret'])) {
                if (in_array($name, $clear, true)) {
                    unset($creds[$name]);
                    $changed[] = $name;
                } elseif ($given !== null && $given !== '') {
                    $creds[$name] = $given;
                    $changed[] = $name;
                }

                continue;
            }

            if ($given !== null && ($plain[$name] ?? null) !== $given) {
                if (($f['type'] ?? '') === 'url' && $given !== '' && ! preg_match(app()->environment('local') ? '~^https?://[^\s<>"\']+$~' : '~^https://[^\s<>"\']+$~', $given)) {
                    throw new StockException('Addresses must start with https://');
                }

                if (($f['type'] ?? '') === 'select' && ! in_array($given, $f['options'], true)) {
                    throw new StockException('Pick one of the choices.');
                }

                if (! empty($f['pattern']) && $given !== '' && ! preg_match($f['pattern'], $given)) {
                    throw new StockException(__($f['pattern_hint'] ?? 'That does not look right.'));
                }

                $plain[$name] = mb_substr($given, 0, 300);
                $changed[] = $name;
            }
        }

        if (! in_array($mode, ['test', 'live'], true)) {
            throw new StockException('Pick test or live.');
        }

        if ($enabled) {
            if (! $p['ready']) {
                throw new StockException('This service is not built into the site yet, so it cannot be switched on. Your keys are saved for when it is.');
            }

            foreach ($p['fields'] as $f) {
                $have = ! empty($f['secret']) ? ($creds[$f['name']] ?? null) : ($plain[$f['name']] ?? ($f['default'] ?? null));
                if (! empty($f['required']) && ($have === null || $have === '')) {
                    throw new StockException(__('Fill in "{field}" before switching it on.', ['field' => __($f['label'])]));
                }
            }
        }

        // Notifications by Web Push need a pair of signing keys, so the site makes them when the owner has none (nothing to buy or paste).
        if ($key === 'push:webpush' && $enabled && (empty($plain['public_key']) || empty($creds['private_key']))) {
            $pair = Notifications::makeKeys();
            $plain['public_key'] = $pair['public_key'];
            $creds['private_key'] = $pair['private_key'];
            $changed[] = 'public_key';
            $changed[] = 'private_key';
        }

        // The password PharmaTrack sends to us is made when the link is first switched on; the PharmaTrack screen shows it once and can make a new one.
        if ($key === PharmaTrack::KEY && $enabled && empty($creds['inbound_token'])) {
            $creds['inbound_token'] = PharmaTrack::newToken();
            $changed[] = 'inbound_token';
        }

        $type = explode(':', $key)[0];

        // Only one text service and one map at a time.
        if ($enabled && config("integrations.types.{$type}.single")) {
            Integration::where('type', $type)->where('key', '!=', $key)->where('enabled', true)->update(['enabled' => false]);
        }

        $row->fill(['type' => $type, 'enabled' => $enabled, 'mode' => $mode, 'credentials' => $creds ?: null, 'settings' => $plain, 'updated_by' => $by->id])->save();
        Cache::forget(self::CACHE);
        $this->activity->record('integration.saved', null, ['service' => $key, 'enabled' => $enabled, 'mode' => $mode, 'fields' => $changed], $by);

        return $row;
    }

    /** Google Maps does two jobs (naming a place, and road distance for restaurants), so the test tries both and says which one failed. */
    private function tryGoogle(array $values): array
    {
        $place = app(Locator::class)->probe('map:google', $values);
        $road = app(RoadDistance::class)->probe($values);

        return ['ok' => $place['ok'] && $road['ok'], 'note' => trim($place['note'].' '.$road['note'])];
    }

    /** A harmless call to prove the keys. */
    private function tryCourier(string $key): array
    {
        try {
            app(CourierBooking::class)->provider(substr($key, strlen('courier:')))->check();
        } catch (CourierException $e) {
            return ['ok' => false, 'note' => $e->getMessage()];
        }

        return ['ok' => true, 'note' => 'Connected.'];
    }

    /** Forget what is remembered about which services are on (after a change made outside save()). */
    public function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** The saved values of an on service, for the code that uses it: ['mode', 'settings', 'credentials']. Null when it is off. */
    public function active(string $key): ?array
    {
        $on = Cache::remember(self::CACHE, 300, fn () => Integration::where('enabled', true)->pluck('key')->all());

        if (! in_array($key, $on, true)) {
            return null;
        }

        $row = Integration::where('key', $key)->first();

        return $row ? ['mode' => $row->mode, 'settings' => (array) $row->settings, 'credentials' => (array) $row->credentials] : null;
    }

    /** The one switched-on service of a kind that allows only one (the text service, the map): [key, values]. */
    public function activeOfType(string $type): ?array
    {
        $key = Integration::where('type', $type)->where('enabled', true)->value('key');

        return $key ? [$key, $this->active($key)] : null;
    }

    /**
     * Try a service. Only a service whose code is built can be tried. The answer is remembered so the screen can show when it last worked.
     *
     * @return array{ok: bool, note: string}
     *
     * @throws StockException
     */
    public function test(string $key, array $args, User $by): array
    {
        $p = $this->provider($key);

        if (! $p['ready']) {
            throw new StockException('This service is not built into the site yet.');
        }

        $row = Integration::where('key', $key)->first() ?? throw new StockException('Save the keys first.');
        $values = ['mode' => $row->mode, 'settings' => (array) $row->settings, 'credentials' => (array) $row->credentials];

        $result = match ($key) {
            'sms:http' => app(HttpSmsGateway::class)->probe($values, (string) ($args['to'] ?? '')),
            'courier:steadfast', 'courier:pathao' => $this->tryCourier($key),
            'push:webpush' => app(Notifications::class)->probe($values),
            'erp:pharmatrack' => app(PharmaTrack::class)->probe($values),
            'analytics:meta_pixel', 'analytics:ga4' => ['ok' => true, 'note' => __('The number is written correctly. Open the site as a visitor and watch the live view in the tool to see it arrive.')],
            'map:nominatim' => app(Locator::class)->probe($key, $values),
            'map:google' => $this->tryGoogle($values),
            default => throw new StockException(str_starts_with($key, 'social:') ? 'There is no test button for this one. Try signing in with it.' : (str_starts_with($key, 'payment:') ? 'There is no test button for this one. Switch on Testing and make a practice payment.' : 'This service is not built into the site yet.')),
        };

        $row->update(['tested_at' => now(), 'test_ok' => $result['ok'], 'test_note' => mb_substr($result['note'], 0, 300)]);
        $this->activity->record('integration.tested', null, ['service' => $key, 'ok' => $result['ok']], $by);

        return $result;
    }
}
