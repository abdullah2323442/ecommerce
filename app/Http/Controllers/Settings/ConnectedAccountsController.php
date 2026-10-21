<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Services\Security\SocialLogin;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A person joins or removes their Google and Facebook accounts. */
class ConnectedAccountsController extends Controller
{
    public function __construct(private SocialLogin $social) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/connected-accounts', [
            'linked' => $this->social->linked($user), 'available' => $this->social->enabled(), 'hasPassword' => $user->has_password !== false,
            // Staff, the owner, shops and trade accounts keep their password: they cannot sign in this way.
            'allowed' => $user->account_type === AccountType::Public,
        ]);
    }

    public function connect(Request $request, string $provider): RedirectResponse
    {
        if (! $this->social->isOn($provider) || $request->user()->account_type !== AccountType::Public) {
            return back()->withErrors(['social' => __('This way of signing in is not switched on.')]);
        }

        $request->session()->put('social.connect', $provider);

        return $this->social->redirect($provider);
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        try {
            $this->social->disconnect($request->user(), $provider);
        } catch (StockException $e) {
            return back()->withErrors(['social' => __($e->getMessage())]);
        }

        return back()->with('status', __('Disconnected.'));
    }
}
