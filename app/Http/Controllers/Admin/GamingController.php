<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameOrder;
use App\Models\GamePackage;
use App\Services\Audit\ActivityLogger;
use App\Services\Gaming\GameTopUp;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** We add the games and set the packages and prices; we check payments, top up by hand when nothing does it for us, and see what failed (spec ch 18). */
class GamingController extends Controller
{
    public function __construct(private GameTopUp $gaming, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('handle-orders');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : now('Asia/Dhaka')->format('Y-m');
        $row = fn (GameOrder $o) => [
            'id' => $o->id, 'number' => $o->number(), 'game' => $o->game_name, 'package' => $o->package_name, 'price' => (float) $o->price, 'player_id' => $o->player_id, 'zone' => $o->zone, 'player_name' => $o->player_name,
            'phone' => $o->contact_phone, 'status' => $o->status, 'pay_method' => $o->pay_method, 'pay_ref' => $o->pay_ref, 'fail_reason' => $o->fail_reason, 'refund' => $o->refund, 'at' => $o->created_at->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(), 'late' => $this->gaming->isLate($o),
        ];
        $lists = $this->gaming->lists();

        return Inertia::render('admin/gaming', [
            'month' => $month, 'canEdit' => $request->user()->can('manage-accounts'),
            'games' => Game::orderBy('sort')->orderBy('name')->with('packages')->get()->map(fn (Game $g) => ['id' => $g->id, 'name' => $g->name, 'id_label' => $g->id_label, 'needs_zone' => $g->needs_zone, 'zone_label' => $g->zone_label, 'is_active' => $g->is_active,
                'packages' => $g->packages->map(fn (GamePackage $p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price, 'is_active' => $p->is_active])->values()]),
            'toCheck' => $lists['to_check']->map($row)->values(), 'toTopUp' => $lists['to_top_up']->map($row)->values(), 'failed' => $lists['failed']->map($row)->values(), 'done' => $lists['done']->map($row)->values(),
            'sales' => $this->gaming->sales($month), 'pay' => $this->gaming->payNumbers(), 'lateMinutes' => $this->gaming->lateMinutes(),
        ]);
    }

    public function storeGame(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');

        return $this->attempt(fn () => $this->gaming->saveGame($this->gameData($request)), 'Saved.');
    }

    public function updateGame(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('manage-accounts');

        return $this->attempt(fn () => $this->gaming->saveGame($this->gameData($request), $game), 'Saved.');
    }

    public function storePackage(Request $request, Game $game): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'price' => ['required', 'numeric', 'gt:0', 'max:1000000']]);

        return $this->attempt(fn () => $this->gaming->savePackage($game, $data), 'Saved.');
    }

    public function updatePackage(Request $request, GamePackage $package): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'price' => ['required', 'numeric', 'gt:0', 'max:1000000'], 'is_active' => ['required', 'boolean']]);

        return $this->attempt(fn () => $this->gaming->savePackage($package->game, $data, $package), 'Saved.');
    }

    /** One route for each thing we do to an order: accept the payment, turn it down, topped up, failed, refunded, cancel. */
    public function act(Request $request, GameOrder $order, string $action): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless(in_array($action, ['accept', 'reject', 'done', 'fail', 'refunded', 'cancel'], true), 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200'], 'ref' => ['nullable', 'string', 'max:60']]);
        $me = $request->user();

        $result = $this->attempt(fn () => match ($action) {
            'accept' => $this->gaming->acceptPayment($order, $me),
            'reject' => $this->gaming->rejectPayment($order, $me, (string) ($data['note'] ?? '')),
            'done' => $this->gaming->done($order, $me, $data['ref'] ?? null),
            'fail' => $this->gaming->fail($order, $me, (string) ($data['note'] ?? '')),
            'refunded' => $this->gaming->refunded($order, $me),
            'cancel' => $this->gaming->cancel($order, 'staff'),
        }, 'Done.');
        $this->activity->record('gaming.'.$action, $order->buyer, ['order' => $order->number()], $me);

        return $result;
    }

    private function gameData(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'id_label' => ['nullable', 'string', 'max:40'], 'needs_zone' => ['nullable', 'boolean'], 'zone_label' => ['nullable', 'string', 'max:40'], 'is_active' => ['nullable', 'boolean']]);
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['gaming' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
