<?php

namespace App\Http\Middleware;

use App\Services\Sync\PharmaTrack;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** PharmaTrack proves itself with the password the owner gave it. Anything else, and anything while the link is off, is refused with a plain 401 (no page, no hint). */
class VerifyPharmaTrackToken
{
    public function __construct(private PharmaTrack $sync) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->sync->accepts($request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
