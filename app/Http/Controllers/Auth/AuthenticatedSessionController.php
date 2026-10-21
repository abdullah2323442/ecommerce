<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        if ($pending = $request->authenticate()) {
            $request->session()->regenerate();
            $request->session()->put('two_factor', ['id' => $pending->id, 'remember' => $request->boolean('remember'), 'until' => now()->addMinutes(10)->getTimestamp()]);

            return to_route('two-factor.challenge');
        }

        $request->session()->regenerate();

        return redirect()->intended($this->homeFor($request->user()));
    }

    /** Where each kind of person starts after signing in, unless they were sent here from a page they asked for. */
    private function homeFor(User $user): string
    {
        return match ($user->account_type) {
            AccountType::Paikari => '/paikari',
            AccountType::Wholesaler => '/wholesale',
            AccountType::Mpo => '/mpo',
            AccountType::FoodVendor => '/food/vendor',
            AccountType::Craftsman => '/craftsman',
            default => route('dashboard', absolute: false),
        };
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
