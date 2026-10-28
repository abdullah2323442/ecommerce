<?php

namespace App\Http\Controllers;

use App\Services\Stock\StockException;
use App\Services\Wallet\Referrals;
use App\Services\Wallet\RewardPoints;
use App\Services\Wallet\Tokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The buyer's wallet (spec ch 24): what works everywhere, and what works only in one sector. */
class WalletController extends Controller
{
    public function __construct(private Tokens $tokens) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('wallet', [
            'wallet' => $this->tokens->wallet($user), 'history' => $this->tokens->history($user), 'referral' => app(Referrals::class)->summary($user),
            'points' => app(RewardPoints::class)->summary($user),
        ]);
    }

    /** Turn reward points into an Aqua Token that works in every sector. */
    public function points(Request $request, RewardPoints $points): RedirectResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'min:1', 'max:10000000']]);

        try {
            $token = $points->convert($request->user(), (int) $data['points']);
        } catch (StockException $e) {
            return back()->withErrors(['points' => __($e->getMessage())]);
        }

        return back()->with('status', __('Done. You have a token of :amount.', ['amount' => number_format((float) $token->amount, 2)]));
    }
}
