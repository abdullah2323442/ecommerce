<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Security\SocialLogin;
use App\Services\Security\TwoFactor;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Sign in with Google or Facebook. The outside account proves who the person is; the second step, if they have it on, is still asked. */
class SocialLoginController extends Controller
{
    public function __construct(private SocialLogin $social, private TwoFactor $twoFactor) {}

    public function redirect(string $provider): RedirectResponse
    {
        if (! $this->social->isOn($provider)) {
            return redirect()->route('login')->withErrors(['email' => __('This way of signing in is not switched on.')]);
        }

        return $this->social->redirect($provider);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        // A signed-in person coming back from "connect" in Settings.
        if ($request->user() && $request->session()->pull('social.connect') === $provider) {
            try {
                $this->social->connect($request->user(), $provider);
            } catch (StockException $e) {
                return to_route('connected-accounts.show')->withErrors(['social' => __($e->getMessage())]);
            } catch (Throwable $e) {
                report($e);

                return to_route('connected-accounts.show')->withErrors(['social' => __('The sign-in did not work. Please try again.')]);
            }

            return to_route('connected-accounts.show')->with('status', __('Connected.'));
        }

        // The person pressed Cancel at Google or Facebook.
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('login')->withErrors(['email' => __('The sign-in was cancelled.')]);
        }

        try {
            ['user' => $user] = $this->social->resolve($provider);
        } catch (StockException $e) {
            return redirect()->route('login')->withErrors(['email' => __($e->getMessage())]);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['email' => __('The sign-in did not work. Please try again.')]);
        }

        $request->session()->regenerate();

        if ($this->twoFactor->enabled($user)) {
            $request->session()->put('two_factor', ['id' => $user->id, 'remember' => false, 'until' => now()->addMinutes(10)->getTimestamp()]);

            return to_route('two-factor.challenge');
        }

        Auth::login($user);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
