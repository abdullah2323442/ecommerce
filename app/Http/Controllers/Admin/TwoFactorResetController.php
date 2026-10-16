<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The owner switches two-step sign-in off for someone who lost their phone and their recovery codes. They set it up again after signing in. */
class TwoFactorResetController extends Controller
{
    public function reset(Request $request, User $user, TwoFactor $twoFactor): RedirectResponse
    {
        $this->authorize('manage-accounts');
        abort_if($user->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 404);

        $twoFactor->reset($user, $request->user());

        return back()->with('status', __('Two-step sign-in is off for them. They can set it up again.'));
    }
}
