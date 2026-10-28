<?php

namespace App\Services\Analytics;

use App\Enums\AccountType;
use App\Services\Integrations\Integrations;
use Illuminate\Http\Request;

/**
 * The visitor-statistics tags the owner switched on in Connections (spec ch 27, Facebook Pixel and Analytics): the Meta Pixel and Google Analytics. Only the
 * ids the owner typed are used, and only when the service is on and set to Live. Staff, the owner and the admin screens are never counted, so the figures are
 * about buyers and visitors.
 */
class Tags
{
    public function __construct(private Integrations $integrations) {}

    /**
     * @return array{meta_pixel: ?string, ga4: ?string}|null null when nothing is on or this visitor must not be counted
     */
    public function forRequest(Request $request): ?array
    {
        $user = $request->user();

        if ($request->is('admin', 'admin/*') || ($user && in_array($user->account_type, [AccountType::Admin, AccountType::Staff], true))) {
            return null;
        }

        $ids = [
            'meta_pixel' => $this->live('analytics:meta_pixel', 'pixel_id', '/^\d{10,20}$/'),
            'ga4' => $this->live('analytics:ga4', 'measurement_id', '/^G-[A-Z0-9]{4,14}$/'),
        ];

        return $ids['meta_pixel'] === null && $ids['ga4'] === null ? null : $ids;
    }

    /** The id of a service that is on and live, if it is still written the way the service writes it (it goes into a page, so it is checked again here). */
    private function live(string $key, string $field, string $pattern): ?string
    {
        $on = $this->integrations->active($key);
        $id = (string) ($on['settings'][$field] ?? '');

        return $on !== null && $on['mode'] === 'live' && preg_match($pattern, $id) ? $id : null;
    }
}
