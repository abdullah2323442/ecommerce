<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The second step of signing in: the six-digit code from the phone, or one recovery code. Only reachable right after the password was proven. */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function create(Request $request): Response|RedirectResponse
    {
        return $this->pending($request) ? Inertia::render('auth/two-factor-challenge') : redirect()->route('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pending($request);

        if (! $user) {
            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:30']]);
        $key = 'two-factor|'.$user->id.'|'.$request->ip();

        // Five wrong tries a minute: a six-digit code must not be guessable.
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('Too many tries. Wait a minute and try again.')]);
        }

        $ok = filled($data['code'] ?? null) ? $this->twoFactor->accept($user, (string) $data['code'])
            : (filled($data['recovery_code'] ?? null) && $this->twoFactor->useRecoveryCode($user, (string) $data['recovery_code']));

        if (! $ok) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code' => __('That code is not right.')]);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->get('two_factor.remember', false);
        $request->session()->forget('two_factor');
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** The person who proved their password in the last ten minutes, or null. */
    private function pending(Request $request): ?User
    {
        $p = $request->session()->get('two_factor');

        if (! is_array($p) || ($p['until'] ?? 0) < time()) {
            $request->session()->forget('two_factor');

            return null;
        }

        $user = User::find($p['id']);

        return $user && $this->twoFactor->enabled($user) ? $user : null;
    }
}
