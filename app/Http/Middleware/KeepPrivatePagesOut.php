<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells search engines to leave the private side of the site alone, even if a link to it leaks (spec ch 27: SEO; ch 05: nothing hidden is found).
 * The staff screens, the trader and shop portals, anything under a signed-in account, and every page that is only for the holder of its link.
 */
class KeepPrivatePagesOut
{
    /** Page starts that are never for search engines. */
    private const PRIVATE = [
        'admin', 'dashboard', 'settings', 'paikari', 'wholesale', 'mpo', 'craftsman', 'food/vendor', 'orders', 'market/orders', 'market-orders', 'market-offers',
        'food/orders', 'gaming/orders', 'gaming-orders', 'custom', 'inbox', 'wallet', 'cart', 'checkout', 'my-orders', 'preorders', 'memo', 'business', 'quotes',
        'receipt', 'login', 'register', 'forgot-password', 'reset-password', 'confirm-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $path = trim($request->path(), '/');

        foreach (self::PRIVATE as $start) {
            if ($path === $start || str_starts_with($path, $start.'/')) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
                break;
            }
        }

        return $response;
    }
}
