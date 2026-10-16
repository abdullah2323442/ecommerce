<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Models\Setting;
use App\Services\Security\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the owner has said staff must use two-step sign-in (Settings > Security), a staff account or the owner without it can only reach the page that sets it up.
 * Off by default, so nothing changes until the owner decides.
 */
class RequireTwoFactorForStaff
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && in_array($user->account_type, [AccountType::Staff, AccountType::Admin], true) && Setting::read('security.require_2fa_staff', false) && ! $this->twoFactor->enabled($user)) {
            return redirect()->route('two-factor.show')->with('status', __('Please turn on two-step sign-in to use the admin panel.'));
        }

        return $next($request);
    }
}
