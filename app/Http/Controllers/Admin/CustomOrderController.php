<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomMaterial;
use App\Models\CustomOrder;
use App\Models\CustomProgress;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Custom\Craftsmen;
use App\Services\Custom\CustomOrders;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** We price made-to-order work as a memo, take the advance, start it, and answer "work not done right" (spec ch 16). */
class CustomOrderController extends Controller
{
    public function __construct(private CustomOrders $orders, private ActivityLogger $activity, private Craftsmen $craftsmen) {}

    public function index(): Response
    {
        $this->authorize('handle-orders');
        $list = fn (array $status) => CustomOrder::whereIn('status', $status)->with(['buyer:id,name,business_name', 'events'])->oldest('id')->limit(60)->get()->map(fn (CustomOrder $o) => $this->row($o));

        return Inertia::render('admin/custom-orders', [
            'asking' => $list(['requested']), 'priced' => $list(['priced']), 'confirmed' => $list(['confirmed']), 'making' => $list(['making', 'trial']), 'done' => $list(['done']),
            'complaints' => CustomOrder::where('complaint_status', 'open')->with(['buyer:id,name,business_name', 'events'])->get()->map(fn (CustomOrder $o) => $this->row($o)),
            'materials' => CustomMaterial::orderBy('kind')->orderBy('sort')->orderBy('name')->get(),
            'craftsmen' => $this->craftsmen->all()->reject->isSuspended()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'active_jobs' => $this->craftsmen->activeJobs($u)])->values(),
            'defaults' => ['advance' => $this->orders->defaultAdvance(), 'urgent' => $this->orders->urgentPercent()],
        ]);
    }

    public function price(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate([
            'material_cost' => ['required', 'numeric', 'min:0', 'max:100000000'], 'wages_total' => ['required', 'numeric', 'min:0', 'max:100000000'], 'base_price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'material_qty' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'advance_percent' => ['nullable', 'integer', 'min:0', 'max:100'], 'days_needed' => ['required', 'integer', 'min:1', 'max:365'], 'memo_note' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->act($request, $order, fn () => $this->orders->price($order, $request->user(), $data), 'Sent to the buyer.');
    }

    public function advance(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:100000000']]);

        return $this->act($request, $order, fn () => $this->orders->receiveAdvance($order, $request->user(), (float) $data['amount']), 'Noted.');
    }

    /** One route for the steps that need nothing more than a click. */
    public function step(Request $request, CustomOrder $order, string $step): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless(in_array($step, ['start', 'trial', 'trial-done', 'done', 'collect'], true), 404);

        $do = fn () => match ($step) {
            'start' => $this->orders->start($order, $request->user()),
            'trial' => $this->orders->callTrial($order, $request->user()),
            'trial-done' => $this->orders->endTrial($order, $request->user()),
            'done' => $this->orders->markDone($order, $request->user()),
            'collect' => $this->orders->collect($order, $request->user()),
        };

        return $this->act($request, $order, $do, 'Done.');
    }

    // ------------------------------------------------------------------ craftsmen

    public function askWage(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['craftsman_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->act($request, $order, fn () => $this->craftsmen->askWage($order, $request->user(), User::findOrFail($data['craftsman_id'])), 'Asked.');
    }

    public function wages(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:100000000']]);

        return $this->act($request, $order, fn () => $this->craftsmen->setWages($order, $request->user(), (float) $data['amount']), 'The wage is set.');
    }

    public function assign(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['craftsman_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->act($request, $order, fn () => $this->craftsmen->assign($order, $request->user(), User::findOrFail($data['craftsman_id'])), 'Given to the craftsman.');
    }

    public function extend(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);

        return $this->act($request, $order, fn () => $this->craftsmen->extend($order, $request->user(), (int) $data['days']), 'A new time is set.');
    }

    public function giveAdvance(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:100000000']]);

        return $this->act($request, $order, fn () => $this->craftsmen->giveAdvance($order, $request->user(), (float) $data['amount']), 'Noted.');
    }

    public function materialsArrived(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');

        return $this->act($request, $order, fn () => $this->craftsmen->materialsArrived($order, $request->user()), 'Noted.');
    }

    /** The split is in dispute, or was entered wrongly: we can see it and fix it. */
    public function correctProgress(Request $request, CustomProgress $entry): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['percent' => ['required', 'numeric', 'gt:0', 'max:100'], 'craftsman_id' => ['nullable', 'integer', 'exists:users,id']]);
        $order = $entry->order;

        return $this->act($request, $order, fn () => $this->craftsmen->correct($entry, $request->user(), (float) $data['percent'], isset($data['craftsman_id']) ? User::findOrFail($data['craftsman_id']) : null), 'Corrected.');
    }

    public function progressPhoto(CustomProgress $entry): StreamedResponse
    {
        $this->authorize('handle-orders');
        abort_unless(Storage::exists($entry->photo_path), 404);

        return Storage::response($entry->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function cancel(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        return $this->act($request, $order, fn () => $this->orders->cancel($order, $request->user(), 'staff', $data['reason']), 'Cancelled.');
    }

    public function complaint(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['outcome' => ['required', 'in:fixed,refunded,buyer'], 'note' => ['required', 'string', 'min:3', 'max:300'], 'refund' => ['nullable', 'numeric', 'gt:0']]);

        return $this->act($request, $order, fn () => $this->orders->resolveComplaint($order, $request->user(), $data['outcome'], $data['note'], isset($data['refund']) ? (float) $data['refund'] : null), 'Answered.');
    }

    public function photo(CustomOrder $order): StreamedResponse
    {
        $this->authorize('handle-orders');
        abort_unless($order->photo_path !== null && Storage::exists($order->photo_path), 404);

        return Storage::response($order->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    // ------------------------------------------------------------------ woods and fabrics

    public function storeMaterial(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $material = CustomMaterial::create($this->materialData($request));
        $this->activity->record('custom.material_created', null, ['material' => $material->name], $request->user());

        return back()->with('status', __('Saved.'));
    }

    public function updateMaterial(Request $request, CustomMaterial $material): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $material->update($this->materialData($request));
        $this->activity->record('custom.material_changed', null, ['material' => $material->name, 'rate' => $material->rate], $request->user());

        return back()->with('status', __('Saved.'));
    }

    private function materialData(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'in:wood,fabric'], 'name' => ['required', 'string', 'max:60'], 'name_bn' => ['nullable', 'string', 'max:60'],
            'rate' => ['required', 'numeric', 'min:0', 'max:10000000'], 'stock' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'is_active' => ['required', 'boolean'], 'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]) + ['sort' => 0];
    }

    private function act(Request $request, CustomOrder $order, callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['custom' => __($e->getMessage())]);
        }
        $this->activity->record('custom.'.($request->route()->getName() ?? 'step'), $order->buyer, ['order' => $order->number()], $request->user());

        return back()->with('status', __($done));
    }

    /** Everything about the job including what it cost us: only here, never to the buyer. */
    private function row(CustomOrder $o): array
    {
        $cost = (float) ($o->material_cost ?? 0) + (float) ($o->wages_total ?? 0);

        return $this->orders->forBuyer($o) + [
            'id' => $o->id, 'buyer' => $o->buyer->business_name ?: $o->buyer->name, 'material_cost' => $o->material_cost !== null ? (float) $o->material_cost : null, 'material_qty' => $o->material_qty !== null ? (float) $o->material_qty : null,
            'wages_total' => $o->wages_total !== null ? (float) $o->wages_total : null, 'base_price' => $o->base_price !== null ? (float) $o->base_price : null,
            'craftsman' => $o->craftsman?->name, 'craftsman_id' => $o->craftsman_id, 'wage_asked' => $o->wage_asked_id ? User::find($o->wage_asked_id)?->name : null,
            'wage_proposed' => $o->wage_proposed !== null ? (float) $o->wage_proposed : null, 'wage_note' => $o->wage_proposal_note, 'waiting_note' => $o->waiting_note, 'delays' => (int) $o->delays,
            'advance_given' => (float) $o->advance_given, 'done_percent' => $this->craftsmen->done($o),
            'entries' => $o->progress()->with('craftsman:id,name')->get()->map(fn (CustomProgress $p) => ['id' => $p->id, 'who' => $p->craftsman->name, 'who_id' => $p->craftsman_id, 'percent' => (float) $p->percent, 'note' => $p->note, 'at' => $p->created_at->toIso8601String()])->values(),
            'moves' => $o->moves()->with(['from:id,name', 'to:id,name'])->get()->map(fn ($m) => ['kind' => $m->kind, 'from' => $m->from?->name, 'to' => $m->to?->name, 'at' => $m->created_at->toIso8601String()])->values(),
            'profit' => $o->price !== null ? round((float) $o->price - $cost, 2) : null, 'complaint_open' => $o->complaint_status === 'open',
        ];
    }
}
