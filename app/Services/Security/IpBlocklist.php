<?php

namespace App\Services\Security;

use App\Models\BlockedIp;
use Illuminate\Support\Facades\Cache;

/** Cached lookup of active IP blocks (spec ch 26). Call forget() whenever a block changes. */
class IpBlocklist
{
    private const CACHE_KEY = 'ip_blocklist.active';

    public function isBlocked(string $ip): bool
    {
        return in_array($ip, $this->activeIps(), true);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<string> */
    private function activeIps(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => BlockedIp::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('ip')
            ->all());
    }
}
