<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Preorder;
use App\Models\PreorderLine;
use App\Services\Audit\ActivityLogger;
use App\Services\Sales\PreOrders;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The seller's side of pre-order (spec ch 21): answer, edit the draft memo, receive, extend, cancel, and keep the buying list. */
class PreorderController extends Controller
{
    public function __construct(private PreOrders $preorders, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('handle-orders');

        $row = fn (Preorder $p) => [
            'id' => $p->id, 'mpo' => $p->mpo_listing_id !== null, 'status' => $p->status, 'late' => $p->isLate(), 'due_on' => $p->due_on?->toDateString(), 'days' => (int) $p->promised_days, 'extended' => (int) $p->extended,
            'customer' => $p->customer?->name, 'phone' => $p->customer?->phone, 'created_at' => $p->created_at?->toIso8601String(),
            'advance' => (float) $p->advance_amount, 'advance_state' => $p->advance_state, 'advance_refunded' => (float) $p->advance_refunded, 'total' => $this->preorders->expectedTotal($p), 'invoice_id' => $p->invoice_id,
            'cancelled_by' => $p->cancelled_by, 'reply_note' => $p->reply_note, 'cancel_note' => $p->cancel_note,
            'lines' => $p->lines->map(fn (PreorderLine $l) => [
                'id' => $l->id, 'name' => trim($l->medicine->brand_name.' '.$l->medicine->strength), 'unit' => $l->unit, 'quantity' => (float) $l->quantity,
                'received' => $l->received_quantity === null ? null : (float) $l->received_quantity, 'share' => $l->share_quantity === null ? null : (float) $l->share_quantity,
            ])->values(),
        ];
        $load = ['customer:id,name,phone,account_type', 'lines.medicine:id,brand_name,strength,company_id'];

        return Inertia::render('admin/preorders', [
            'waiting' => Preorder::where('status', 'requested')->with($load)->orderBy('id')->get()->map($row),
            'accepted' => Preorder::where('status', 'accepted')->with($load)->orderBy('due_on')->orderBy('id')->get()->map($row),
            'closed' => Preorder::whereIn('status', ['declined', 'cancelled', 'fulfilled'])->with($load)->latest('updated_at')->limit(20)->get()->map($row),
            'buying' => $this->preorders->buyingList(),
            'on' => [
                'companies' => Company::whereNotNull('preorder_days')->where('preorder_days', '>', 0)->orderBy('name')->get(['id', 'name', 'preorder_days']),
                'medicines' => Medicine::whereNotNull('preorder_days')->where('preorder_days', '>', 0)->orderBy('brand_name')->limit(100)->get(['id', 'brand_name', 'strength', 'preorder_days'])
                    ->map(fn ($m) => ['id' => $m->id, 'name' => trim($m->brand_name.' '.$m->strength), 'preorder_days' => $m->preorder_days]),
            ],
            'days' => PreOrders::DAYS,
        ]);
    }

    /** Companies by name, to turn pre-order on for one. */
    public function companies(Request $request): JsonResponse
    {
        $this->authorize('handle-orders');
        $q = trim((string) $request->query('q', ''));

        return response()->json(['results' => mb_strlen($q) < 2 ? [] : Company::where('name', 'like', addcslashes($q, '%_\\').'%')->orderBy('name')->limit(8)->get(['id', 'name'])]);
    }

    /** The MPO's goods reached us: put them into stock "Through MPO" at the discount we paid. */
    public function takeFromMpo(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->authorize('manage-stock');
        $data = $request->validate(['percent' => ['required', 'numeric', 'min:0', 'lt:100']]);

        return $this->attempt(fn () => $this->preorders->takeFromMpo($preorder, (float) $data['percent'], $request->user()), 'The goods are in stock now. You can receive the order.');
    }

    public function accept(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['days' => ['nullable', 'integer', Rule::in(PreOrders::DAYS)]]);

        return $this->attempt(fn () => $this->preorders->accept($preorder, $data['days'] ?? null, $request->user()), 'Accepted. The buyer is told.');
    }

    public function decline(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => $this->preorders->decline($preorder, $data['note'], $request->user()), 'Declined. The buyer is told.');
    }

    public function cancel(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        return $this->attempt(fn () => $this->preorders->cancelBySeller($preorder, $data['note'], $request->user()), 'Cancelled. The buyer gets a sorry note.');
    }

    public function extend(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['days' => ['required', 'integer', Rule::in(PreOrders::DAYS)]]);

        return $this->attempt(fn () => $this->preorders->extend($preorder, (int) $data['days'], $request->user()), 'New time saved. The buyer is told.');
    }

    public function advance(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['state' => ['required', 'in:held,refunded,half,kept']]);

        return $this->attempt(fn () => $this->preorders->setAdvance($preorder, $data['state'], $request->user()), 'Saved.');
    }

    public function editLine(Request $request, PreorderLine $line): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0', 'max:100000']]);

        return $this->attempt(fn () => $this->preorders->editLine($line, (float) $data['quantity'], $request->user()), 'Saved.');
    }

    /** Goods came for one medicine: share them out in the order they were asked for (spec ch 21). */
    public function share(Request $request): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['medicine_id' => ['required', 'integer', 'exists:medicines,id'], 'boxes' => ['required', 'numeric', 'gt:0', 'max:100000']]);

        try {
            $plan = $this->preorders->share(Medicine::findOrFail($data['medicine_id']), (float) $data['boxes'], $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['preorder' => __($e->getMessage())]);
        }

        $short = collect($plan)->filter(fn ($r) => $r['give'] < $r['asked'])->count();

        return back()->with('status', $short > 0 ? __('Shared out. {n} pre-orders got less than they asked for and were told.', ['n' => $short]) : __('Shared out. Everyone gets all they asked for.'));
    }

    /** "Receive": write in how much of each arrived. The pre-order becomes the real order for that. */
    public function receive(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['received' => ['required', 'array'], 'received.*' => ['nullable', 'numeric', 'min:0', 'max:100000']]);

        try {
            $order = $this->preorders->fulfil($preorder, $data['received'], $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['preorder' => __($e->getMessage())]);
        }

        return redirect()->route('admin.orders.show', $order)->with('status', __('Received. The order is ready and the buyer is told.'));
    }

    /** Turn pre-order on or off for a company or one medicine, with the time promised. */
    public function availability(Request $request): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate([
            'type' => ['required', 'in:company,medicine'],
            'id' => ['required', 'integer'],
            'days' => ['nullable', 'integer', Rule::in([...PreOrders::DAYS, 0])],
        ]);

        $model = $data['type'] === 'company' ? Company::findOrFail($data['id']) : Medicine::findOrFail($data['id']);
        $days = $data['days'] ?? null;
        $model->forceFill(['preorder_days' => $days === 0 && $data['type'] === 'company' ? null : $days])->save();
        $this->activity->record('preorder.availability', $model, ['days' => $days], $request->user());

        return back()->with('status', __('Saved.'));
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['preorder' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
