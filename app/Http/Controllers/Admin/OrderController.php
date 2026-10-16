<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\ApprovalService;
use App\Services\Sales\ControlledRegister;
use App\Services\Sales\CustomerFlags;
use App\Services\Sales\Deliveries;
use App\Services\Sales\Dispatching;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\Presence;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff side of online orders (spec 4.4): check every line, then hand the order to the counter. */
class OrderController extends Controller
{
    public function __construct(private InvoiceService $invoices, private CustomerFlags $flags) {}

    public function index(): Response
    {
        $this->authorize('handle-orders');

        $orders = Invoice::where('channel', 'online')->whereIn('status', ['placed', 'verified'])->with('customer:id,name,phone')->withCount('lines')
            ->orderBy('placed_at')->get()
            ->map(fn (Invoice $i) => ['id' => $i->id, 'status' => $i->status, 'cancel_requested' => $i->cancel_requested_at !== null, 'customer' => $i->customer?->name ?? ($i->ship_to['name'] ?? null), 'phone' => $i->customer?->phone ?? ($i->ship_to['phone'] ?? null), 'public' => $i->public_token !== null, 'lines' => $i->lines_count, 'minutes' => (int) ($i->placed_at ?? $i->opened_at)->diffInMinutes(now())]);

        // What shops asked for in the last 30 days and we could not supply (spec 4.4.5): the buying list.
        $missed = DB::table('invoice_lines')
            ->join('medicines', 'medicines.id', '=', 'invoice_lines.medicine_id')
            ->where('invoice_lines.verify_status', 'unavailable')->where('invoice_lines.updated_at', '>=', now()->subDays(30))
            ->groupBy('medicines.id', 'medicines.brand_name', 'medicines.strength')
            ->orderByDesc(DB::raw('count(*)'))->limit(15)
            ->get(['medicines.brand_name as name', 'medicines.strength', DB::raw('count(*) as times'), DB::raw('sum(invoice_lines.requested_quantity) as quantity')]);

        return Inertia::render('admin/orders/index', ['orders' => $orders, 'missed' => $missed]);
    }

    public function show(Invoice $invoice): Response
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);
        $invoice->load('customer:id,name,phone', 'lines.medicine.company');

        app(ApprovalService::class)->expireOld();

        return Inertia::render('admin/orders/show', ['controlled' => app(ControlledRegister::class)->panel($invoice), 'controlledDefaults' => ['buyer_name' => $invoice->customer?->name ?? ($invoice->ship_to['name'] ?? null), 'buyer_phone' => $invoice->customer?->phone ?? ($invoice->ship_to['phone'] ?? null)], 'approvals' => ApprovalRequest::where('invoice_id', $invoice->id)->latest('id')->limit(6)->get()->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'line_id' => $r->invoice_line_id, 'value' => (float) $r->value, 'status' => $r->status])->all(), 'order' => [
            'id' => $invoice->id, 'status' => $invoice->status, 'cancel_requested' => $invoice->cancel_requested_at !== null, 'customer' => $invoice->customer?->name ?? ($invoice->ship_to['name'] ?? null), 'phone' => $invoice->customer?->phone ?? ($invoice->ship_to['phone'] ?? null), 'ship_to' => $invoice->ship_to, 'payment_terms' => $invoice->payment_terms, 'public' => $invoice->public_token !== null,
            'flagged' => $invoice->customer ? $this->flags->isFlagged($invoice->customer) : false,
            'late_cancellations' => $invoice->customer ? $this->flags->lateCancellations($invoice->customer) : 0,
            'delivery_mode' => $invoice->delivery_mode, 'note' => $invoice->note, 'wanted_time' => $invoice->wanted_time, 'has_voice' => $invoice->voice_path !== null, 'has_photo' => $invoice->photo_path !== null, 'total' => $this->invoices->totals($invoice)['total'],
            'working' => app(Presence::class)->otherOn($invoice, request()->user()),
            'people' => app(Deliveries::class)->people()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'delivery_person' => $invoice->delivery_person_id ? User::whereKey($invoice->delivery_person_id)->value('name') : null, 'arrived_at' => $invoice->arrived_at?->toIso8601String(),
            'courier_id' => $invoice->courier_id, 'courier_panel' => app(\App\Services\Couriers\CourierBooking::class)->panel($invoice->loadMissing('courier')),
            'trade' => $invoice->kind !== 'retail', 'courier_terms' => $invoice->courier_terms, 'courier_cost' => $invoice->courier_cost !== null ? (float) $invoice->courier_cost : null, 'cartons' => $invoice->cartons, 'delivery_refund' => (float) $invoice->delivery_refund, 'advance_needed' => app(Dispatching::class)->advanceRequired($invoice), 'advance_at' => $invoice->delivery_advance_at?->toIso8601String(), 'sent_at' => $invoice->sent_at?->toIso8601String(), 'tracking' => $invoice->tracking, 'delivery_extra' => (float) $invoice->delivery_extra,
            'delivered_at' => $invoice->delivered_at?->toIso8601String(), 'delivered_by' => $invoice->delivered_by, 'not_received' => $invoice->not_received_at !== null,
            'subtotal' => $this->invoices->totals($invoice)['subtotal'], 'delivery_charge' => (float) $invoice->delivery_charge, 'free_over' => (float) Setting::read('delivery.free_over', 0),
            'lines' => $invoice->lines->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'company' => $l->medicine->company?->name,
                'cold' => (bool) $l->medicine->is_cold_chain, 'unit' => $l->unit, 'requested' => (float) ($l->requested_quantity ?? $l->quantity), 'quantity' => (float) $l->quantity,
                'check' => $l->verify_status, 'checked_by' => $l->checked_by ? User::whereKey($l->checked_by)->value('name') : null, 'unit_price' => (float) $l->unit_price, 'offer' => $l->offer_product_id !== null,
                'offer_delivery' => $l->offer?->delivery_charge !== null ? (float) $l->offer->delivery_charge : null,
            ])->values(),
        ]]);
    }

    /** The voice note or the photo the buyer sent with the order, for staff only. */
    public function attachment(Invoice $invoice, string $kind): StreamedResponse
    {
        $this->authorize('handle-orders');
        $path = $kind === 'voice' ? $invoice->voice_path : $invoice->photo_path;
        abort_unless($path !== null && Storage::exists($path), 404);

        return Storage::response($path);
    }

    public function verify(Request $request, Invoice $invoice, InvoiceLine $line): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($line->invoice_id === $invoice->id, 404);
        $data = $request->validate(['status' => ['required', 'in:full,partial,unavailable'], 'quantity' => ['nullable', 'numeric', 'gt:0'], 'expected' => ['nullable', 'in:pending,full,partial,unavailable']]);

        return $this->attempt(fn () => $this->invoices->verifyLine($line, $data['status'], isset($data['quantity']) ? (float) $data['quantity'] : null, $request->user(), $data['expected'] ?? null));
    }

    /** Staff answer a shop's request to cancel an order they have started on. */
    public function cancelDecision(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online' && $invoice->cancel_requested_at !== null, 404);
        $data = $request->validate(['approve' => ['required', 'boolean']]);

        if ($data['approve']) {
            return $this->attempt(fn () => $this->invoices->cancel($invoice, $request->user(), __('You asked us to cancel it.')), 'Order cancelled.');
        }

        $this->invoices->declineCancel($invoice);

        return back()->with('status', __('Request declined.'));
    }

    public function notCollected(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);

        return $this->attempt(fn () => $this->invoices->notCollected($invoice, $request->user()), 'Order cancelled and the goods are back in stock.');
    }

    public function price(Request $request, Invoice $invoice, InvoiceLine $line): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($line->invoice_id === $invoice->id, 404);
        $data = $request->validate(['price' => ['required', 'numeric', 'gt:0', 'max:10000000']]);

        return $this->attempt(fn () => $this->invoices->setLinePrice($line, (float) $data['price'], $request->user()), 'Price saved.');
    }

    /** The delivery charge is typed by hand for each order (spec 4.4.6). */
    public function delivery(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online' && in_array($invoice->status, ['placed', 'verified'], true), 404);
        $data = $request->validate(['charge' => ['required', 'numeric', 'min:0', 'max:100000']]);
        $invoice->update(['delivery_charge' => $data['charge']]);

        return back()->with('status', __('Delivery charge saved.'));
    }

    /** Mark a checked order as sent, with a tracking note and any extra delivery charge the real cost came to. */
    public function deliveryAdvance(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);

        return $this->attempt(fn () => app(Dispatching::class)->receiveAdvance($invoice, $request->user()), 'Noted. The delivery charge is received.');
    }

    public function send(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);
        $data = $request->validate(['extra' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'tracking' => ['nullable', 'string', 'max:80'], 'terms' => ['nullable', 'in:split,fixed'], 'courier_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'cartons' => ['nullable', 'integer', 'min:1', 'max:5000']]);

        return $this->attempt(fn () => app(Dispatching::class)->send($invoice, $request->user(), (float) ($data['extra'] ?? 0), $data['tracking'] ?? null, $data['terms'] ?? null, isset($data['courier_cost']) ? (float) $data['courier_cost'] : null, $data['cartons'] ?? null), 'Marked as sent. The buyer is told.');
    }

    /** The owner looks into a "not received" report: it did arrive after all, or it is lost. */
    public function settleNotReceived(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->not_received_at !== null, 404);
        $data = $request->validate(['arrived' => ['required', 'boolean']]);

        if ($data['arrived']) {
            app(Dispatching::class)->received($invoice);
        } else {
            $invoice->update(['note' => trim(($invoice->note ?? '').' Lost on the way.')]);
        }

        return back()->with('status', __('Saved.'));
    }

    /** The open screen says "I am here" every half minute, so others see who is working on this order. */
    public function working(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('handle-orders');
        $presence = app(Presence::class);
        $other = $presence->otherOn($invoice, $request->user());
        $presence->touch($invoice, $request->user());

        return response()->json(['other' => $other]);
    }

    public function complete(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');

        return $this->attempt(fn () => $this->invoices->completeVerification($invoice, $request->user()), 'Order checked. The buyer can see it now.');
    }

    private function attempt(callable $do, ?string $done = null): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return $done ? back()->with('status', __($done)) : back();
    }
}
