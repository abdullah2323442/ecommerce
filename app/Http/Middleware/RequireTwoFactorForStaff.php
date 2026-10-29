<?php

namespace App\Http\Middleware;

use App\Services\Security\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When two-step sign-in is required for an account (see TwoFactor::requiredFor), that account without it can only reach the page that sets it up.
 * Staff and the owner are asked by default on the live site; shops only when the owner switches it on in Settings > Security.
 */
class RequireTwoFactorForStaff
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->twoFactor->requiredFor($user) && ! $this->twoFactor->enabled($user)) {
            return redirect()->route('two-factor.show')->with('status', __('Please turn on two-step sign-in to carry on.'));
        }

        return $next($request);
    }
}
