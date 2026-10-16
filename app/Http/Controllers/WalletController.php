<?php

namespace App\Http\Controllers;

use App\Services\Wallet\Tokens;
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

        return Inertia::render('wallet', ['wallet' => $this->tokens->wallet($user), 'history' => $this->tokens->history($user), 'referral' => app(\App\Services\Wallet\Referrals::class)->summary($user)]);
    }
}
