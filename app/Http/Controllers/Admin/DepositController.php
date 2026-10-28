<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Market\SellerDeposits;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sellers' security deposits (spec ch 14): who keeps how much with us, taking it in, giving it back, and deducting with a reason. */
class DepositController extends Controller
{
    public function __construct(private SellerDeposits $deposits) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-customers');
        $open = $request->integer('seller') ?: null;

        return Inertia::render('admin/market-deposits', [
            'on' => $this->deposits->enabled(),
            'usual' => $this->deposits->usualAmount(),
            'rows' => $this->deposits->sellers(),
            'open' => $open,
            'history' => $open ? $this->deposits->history(User::findOrFail($open)) : [],
        ]);
    }

    public function receive(Request $request, User $user): RedirectResponse
    {
        return $this->act($request, fn (array $d) => $this->deposits->receive($user, (float) $d['amount'], $d['note'] ?? null, $request->user()), $user);
    }

    public function giveBack(Request $request, User $user): RedirectResponse
    {
        return $this->act($request, fn (array $d) => $this->deposits->giveBack($user, (float) $d['amount'], $d['note'] ?? null, $request->user()), $user);
    }

    public function deduct(Request $request, User $user): RedirectResponse
    {
        return $this->act($request, fn (array $d) => $this->deposits->deduct($user, (float) $d['amount'], (string) ($d['note'] ?? ''), $request->user()), $user);
    }

    /** This seller's own required amount; empty goes back to the usual amount. */
    public function required(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['required' => ['nullable', 'numeric', 'min:0', 'max:100000000']]);
        $this->deposits->setRequired($user, isset($data['required']) ? (float) $data['required'] : null, $request->user());

        return to_route('admin.market.deposits', ['seller' => $user->id])->with('status', __('Saved.'));
    }

    /** @param  callable(array<string, mixed>): void  $do */
    private function act(Request $request, callable $do, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'note' => ['nullable', 'string', 'max:200']]);

        try {
            $do($data);
        } catch (StockException $e) {
            return to_route('admin.market.deposits', ['seller' => $user->id])->withErrors(['deposit' => __($e->getMessage())]);
        }

        return to_route('admin.market.deposits', ['seller' => $user->id])->with('status', __('Saved.'));
    }
}
