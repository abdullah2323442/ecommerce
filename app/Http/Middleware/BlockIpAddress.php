<?php

namespace App\Http\Middleware;

use App\Services\Security\IpBlocklist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Refuses requests from blocked IPs before any other work (spec ch 26: IP block). */
class BlockIpAddress
{
    public function __construct(private IpBlocklist $blocklist) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->blocklist->isBlocked((string) $request->ip())) {
            abort(403);
        }

        return $next($request);
    }
}
