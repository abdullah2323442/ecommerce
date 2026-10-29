<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Security\TwoFactor;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** A person turns two-step sign-in on or off for themselves. Anything that weakens it needs the password. */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/two-factor', [
            'enabled' => $this->twoFactor->enabled($user),
            'setup' => $this->twoFactor->pending($user),
            'codesLeft' => $this->twoFactor->enabled($user) ? $this->twoFactor->recoveryCodesLeft($user) : 0,
            // Shown once, straight after turning it on or making new ones; never again.
            'recoveryCodes' => $request->session()->get('recovery_codes'),
            'required' => $this->twoFactor->requiredFor($user),
        ]);
    }

    public function begin(Request $request): RedirectResponse
    {
        // Setting it up needs the password too: someone at an open screen must not be able to lock the owner out with their own phone.
        $data = $request->validate(['password' => ['required', 'string']]);
        $this->check($request, fn () => $this->twoFactor->begin($request->user(), $data['password']), 'password');

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $codes = $this->check($request, fn () => $this->twoFactor->confirm($request->user(), $data['code']), 'code');

        return back()->with('recovery_codes', $codes)->with('status', __('Two-step sign-in is on.'));
    }

    public function codes(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $codes = $this->check($request, fn () => $this->twoFactor->regenerate($request->user(), $data['password']), 'password');

        return back()->with('recovery_codes', $codes)->with('status', __('New recovery codes. The old ones no longer work.'));
    }

    public function disable(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $this->check($request, fn () => $this->twoFactor->disable($request->user(), $data['password']), 'password');

        return back()->with('status', __('Two-step sign-in is off.'));
    }

    /** @throws ValidationException */
    private function check(Request $request, callable $do, string $field = 'password'): mixed
    {
        try {
            return $do();
        } catch (StockException $e) {
            throw ValidationException::withMessages([$field => __($e->getMessage())]);
        }
    }
}
