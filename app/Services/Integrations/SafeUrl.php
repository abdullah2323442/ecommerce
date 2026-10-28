<?php

namespace App\Services\Integrations;

/** An address the owner typed that the server will call: https only, and never this machine or a private network (they must not be able to reach inside). */
class SafeUrl
{
    /** @var (callable(string): array<int, string>)|null How a name becomes addresses. Only tests change this, so they need no network. */
    private static $resolver = null;

    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function ok(string $url): bool
    {
        $parts = parse_url($url);

        // Developing on one computer: this machine is allowed when the site runs in the local environment, never in production.
        if ($parts && app()->environment('local') && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ! isset($parts['user'])) {
            return true;
        }

        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $ips = filter_var($parts['host'], FILTER_VALIDATE_IP) ? [$parts['host']] : ((self::$resolver ? (self::$resolver)($parts['host']) : gethostbynamel($parts['host'])) ?: []);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
