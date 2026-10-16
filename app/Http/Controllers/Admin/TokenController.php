<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AquaToken;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use App\Services\Wallet\Tokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Platform tokens: only we give them, and we decide who gets how much (spec ch 24). */
class TokenController extends Controller
{
    public function __construct(private Tokens $tokens, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-accounts');
        $q = trim((string) $request->query('q', ''));

        $found = $q === '' ? [] : User::where(fn ($w) => $w->where('phone', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")->orWhere('business_name', 'like', "%{$q}%"))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'business_name', 'phone'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->business_name ?: $u->name, 'phone' => $u->phone])->all();

        return Inertia::render('admin/tokens', [
            'q' => $q, 'found' => $found,
            'recent' => AquaToken::with('user:id,name,business_name,phone')->latest('id')->limit(60)->get()->map(fn (AquaToken $t) => [
                'id' => $t->id, 'who' => $t->user->business_name ?: $t->user->name, 'phone' => $t->user->phone, 'amount' => (float) $t->amount, 'note' => $t->note,
                'status' => $t->status(), 'expires_at' => $t->expires_at?->toDateString(), 'at' => $t->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function give(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'], 'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:160'], 'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $to = User::findOrFail($data['user_id']);

        try {
            $token = $this->tokens->give($to, (float) $data['amount'], $data['note'] ?? null, isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['amount' => __($e->getMessage())]);
        }
        $this->activity->record('token.given', $to, ['token' => $token->id, 'amount' => $data['amount']], $request->user());

        return to_route('admin.tokens.index')->with('status', __('Token given.'));
    }

    public function takeBack(Request $request, AquaToken $token): RedirectResponse
    {
        $this->authorize('manage-accounts');

        try {
            $this->tokens->takeBack($token);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['token' => __($e->getMessage())]);
        }
        $this->activity->record('token.taken_back', $token->user, ['token' => $token->id], $request->user());

        return back()->with('status', __('Token taken back.'));
    }
}
