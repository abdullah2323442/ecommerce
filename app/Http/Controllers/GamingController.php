<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameOrder;
use App\Models\GamePackage;
use App\Services\Gaming\GameTopUp;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Game top-up (spec ch 18): open to everyone, no account needed; online payment only. */
class GamingController extends Controller
{
    public function __construct(private GameTopUp $gaming) {}

    public function index(): Response
    {
        return Inertia::render('gaming/index', ['games' => Game::where('is_active', true)->orderBy('sort')->orderBy('name')->get()->map(fn (Game $g) => ['name' => $g->name, 'slug' => $g->slug, 'packages' => $g->packages()->where('is_active', true)->count()])->filter(fn ($g) => $g['packages'] > 0)->values()]);
    }

    public function show(Request $request, Game $game): Response
    {
        abort_unless($game->is_active, 404);

        return Inertia::render('gaming/show', [
            'game' => ['name' => $game->name, 'slug' => $game->slug, 'id_label' => $game->id_label, 'needs_zone' => $game->needs_zone, 'zone_label' => $game->zone_label],
            'packages' => $game->packages()->where('is_active', true)->get()->map(fn (GamePackage $p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price])->values(),
            'me' => $request->user() ? ['phone' => $request->user()->phone] : null,
        ]);
    }

    /** "Whose ID is this?" The name is shown before any money moves, so it does not go to a wrong ID. */
    public function verify(Request $request, Game $game): JsonResponse
    {
        abort_unless($game->is_active, 404);
        $data = $request->validate(['player_id' => ['required', 'string', 'max:60'], 'zone' => ['nullable', 'string', 'max:40']]);

        return response()->json($this->gaming->verify($game, $data['player_id'], $data['zone'] ?? null));
    }

    public function store(Request $request, GamePackage $package): RedirectResponse
    {
        $request->merge(['contact_phone' => $this->phone((string) $request->input('contact_phone'))]);
        $data = $request->validate(['player_id' => ['required', 'string', 'max:60'], 'zone' => ['nullable', 'string', 'max:40'], 'contact_phone' => ['required', 'string'], 'confirmed' => ['required', 'accepted']], ['confirmed.accepted' => __('Confirm that the ID is right.')]);

        try {
            $order = $this->gaming->place($request->user(), $package->load('game'), $data['player_id'], $data['zone'] ?? null, $data['contact_phone'], true);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return to_route('gaming.order', $order->public_token);
    }

    public function order(string $token): Response
    {
        $o = $this->find($token);

        return Inertia::render('gaming/order', [
            'order' => [
                'token' => $o->public_token, 'number' => $o->number(), 'status' => $o->status, 'game' => $o->game_name, 'package' => $o->package_name, 'price' => (float) $o->price, 'player_id' => $o->player_id, 'zone' => $o->zone,
                'player_name' => $o->player_name, 'pay_method' => $o->pay_method, 'pay_note' => $o->pay_note, 'fail_reason' => $o->fail_reason, 'refund' => $o->refund, 'at' => $o->created_at->toIso8601String(),
                'late' => $this->gaming->isLate($o), 'can_cancel' => in_array($o->status, ['placed', 'claimed'], true) || $this->gaming->isLate($o), 'late_minutes' => $this->gaming->lateMinutes(),
            ],
            'pay' => $this->gaming->payNumbers(), 'methods' => GameTopUp::METHODS,
        ]);
    }

    public function claim(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', 'string'], 'ref' => ['required', 'string', 'max:40']]);

        return $this->act(fn () => $this->gaming->claimPayment($this->find($token), $data['method'], $data['ref']), 'Thank you. We will check the payment and top up.');
    }

    public function cancel(string $token): RedirectResponse
    {
        return $this->act(fn () => $this->gaming->cancel($this->find($token)), 'Cancelled.');
    }

    public function mine(Request $request): Response
    {
        return Inertia::render('gaming/orders', [
            'orders' => GameOrder::where('buyer_id', $request->user()->id)->latest('id')->limit(40)->get()
                ->map(fn (GameOrder $o) => ['token' => $o->public_token, 'number' => $o->number(), 'game' => $o->game_name, 'package' => $o->package_name, 'price' => (float) $o->price, 'status' => $o->status, 'at' => $o->created_at->toIso8601String()]),
        ]);
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    private function find(string $token): GameOrder
    {
        return GameOrder::where('public_token', $token)->firstOrFail();
    }

    private function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }
}
