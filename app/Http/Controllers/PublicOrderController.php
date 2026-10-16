<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SaleReturn;
use App\Services\Sales\DeliveryCharge;
use App\Services\Sales\Dispatching;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\OrderNotes;
use App\Services\Sales\PublicOrders;
use App\Services\Sales\ReturnService;
use App\Services\Stock\StockException;
use App\Services\Sales\Deliveries;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Following an order from a visitor or a public buyer (spec ch 13). The private link is the key for a guest; a signed-in
 * buyer also has the list. The buyer sees what staff checked and what is owed, never anything about stock.
 */
class PublicOrderController extends Controller
{
    public function __construct(private InvoiceService $invoices, private PublicOrders $orders, private ReturnService $returnService, private DeliveryCharge $delivery) {}

    public function index(Request $request): Response
    {
        $orders = Invoice::where('customer_id', $request->user()->id)->where('channel', 'online')->where('kind', 'retail')->whereNotNull('public_token')
            ->latest('id')->limit(30)->get()
            ->map(fn (Invoice $i) => ['token' => $i->public_token, 'number' => $i->number, 'status' => $i->status, 'total' => $this->invoices->totals($i)['total'], 'placed_at' => $i->placed_at?->toIso8601String()]);

        return Inertia::render('orders', ['orders' => $orders, 'buyAgain' => (bool) $request->user()->buy_again]);
    }

    /** Turn the "buy again" reminder on or off (spec ch 13). */
    public function buyAgain(Request $request): RedirectResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $request->user()->forceFill(['buy_again' => (bool) $data['on']])->save();

        return back()->with('status', $data['on'] ? __('Reminders are on.') : __('Reminders are off.'));
    }

    public function show(Request $request, string $token): Response
    {
        $order = $this->find($token)->load('lines.medicine.company');
        $totals = $this->invoices->totals($order);

        return Inertia::render('order-public', [
            'token' => $order->public_token,
            'districts' => config('bd.districts'),
            'pay' => app(\App\Services\Payments\Payments::class)->forOrder($order),
            'order' => [
                'status' => $order->status, 'number' => $order->number, 'placed_at' => $order->placed_at?->toIso8601String(), 'note' => $order->note, 'wanted_time' => $order->wanted_time, 'has_voice' => $order->voice_path !== null, 'has_photo' => $order->photo_path !== null, 'can_add_note' => app(OrderNotes::class)->open($order),
                'delivery_mode' => $order->delivery_mode, 'ship_to' => $order->ship_to,
                'subtotal' => $totals['subtotal'], 'delivery' => $totals['delivery'], 'total' => $totals['total'],
                'cancel_minutes' => $this->orders->minutesToCancel($order), 'delivery_refund' => (float) $order->delivery_refund, 'advance_needed' => app(\App\Services\Sales\Dispatching::class)->advanceMissing($order), 'can_edit_items' => $this->orders->minutesToCancel($order) > 0, 'can_change_address' => $this->orders->canChangeAddress($order) && $order->delivery_mode === 'delivery',
                'mine' => $request->user()?->id === $order->customer_id && $order->customer_id !== null,
                'sent_at' => $order->sent_at?->toIso8601String(), 'tracking' => $order->tracking, 'delivery_extra' => (float) $order->delivery_extra,
                'delivered' => $order->delivered_at !== null, 'not_received' => $order->not_received_at !== null,
                // Where the courier company says the parcel is (a fixed set of words; nothing else of theirs is passed on).
                'courier_status' => $order->courier_status ? (\App\Services\Couriers\CourierBooking::STATUS_LABELS[$order->courier_status] ?? 'With the courier') : null,
                // Our own delivery (spec ch 20): the buyer's code for the door, whether the person has arrived, and the rating once it is done.
                'own_delivery' => $order->delivery_person_id !== null && $order->delivered_at === null, 'code' => $order->delivery_person_id !== null && $order->delivered_at === null ? $order->delivery_code : null,
                'arrived' => $order->arrived_at !== null && $order->delivered_at === null, 'can_rate' => app(Deliveries::class)->canRate($order),
                'lines' => $order->lines->map(fn (InvoiceLine $l) => [
                    'id' => $l->id, 'name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'company' => $l->medicine->company?->name,
                    'unit' => $l->unit, 'quantity' => (float) $l->quantity, 'requested' => (float) ($l->requested_quantity ?? $l->quantity),
                    'check' => $l->verify_status, 'unit_price' => (float) $l->unit_price, 'total' => $l->lineTotal(),
                ])->values(),
            ],
        ]);
    }

    /** The delivery and the service, one to five stars each (spec ch 20). Not about the product; the staff are never named. */
    public function rate(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['delivery' => ['required', 'integer', 'between:1,5'], 'service' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:500']]);

        try {
            app(Deliveries::class)->rate($this->find($token), (int) $data['delivery'], (int) $data['service'], $data['comment'] ?? null);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Thank you for your rating.'));
    }

    /** The amount of one item, within the short time the buyer can also cancel in. */
    public function changeLine(Request $request, string $token, int $line): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0', 'max:100000']]);

        try {
            $this->orders->changeLine($this->find($token), $line, (float) $data['quantity']);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Your order is changed.'));
    }

    public function dropLine(string $token, int $line): RedirectResponse
    {
        try {
            $this->orders->dropLine($this->find($token), $line);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Your order is changed.'));
    }

    public function cancel(string $token): RedirectResponse
    {
        $order = $this->find($token);

        try {
            $this->orders->cancelByBuyer($order);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Order cancelled.'));
    }

    /** More for the order after it was sent: a note, a voice note, a photo, the time it is needed by (spec ch 23). */
    public function note(Request $request, string $token): RedirectResponse
    {
        $order = $this->find($token);
        $data = $request->validate(OrderNotes::RULES);

        try {
            app(OrderNotes::class)->addLater($order, ['note' => $data['note'] ?? null, 'wanted_time' => $data['wanted_time'] ?? null, 'voice' => $request->file('voice'), 'photo' => $request->file('photo')]);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Added to your order.'));
    }

    public function received(string $token): RedirectResponse
    {
        try {
            app(Dispatching::class)->received($this->find($token));
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Thank you. Glad it arrived.'));
    }

    public function notReceived(string $token): RedirectResponse
    {
        try {
            app(Dispatching::class)->notReceived($this->find($token));
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('We are sorry. We will look into it and call you.'));
    }

    /** The buyer corrects where it should go, until staff start on it. */
    public function address(Request $request, string $token): RedirectResponse
    {
        $order = $this->find($token);
        $request->merge(['phone' => $this->digits((string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'district' => ['required', Rule::in(config('bd.districts'))],
            'area' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:250'],
            'how_to_find' => ['nullable', 'string', 'max:200'],
            'for_whom' => ['nullable', 'string', 'max:120'],
        ], ['phone.regex' => __('Write a Bangladesh mobile number, like 01712345678.')]);

        try {
            $this->orders->changeAddress($order, $data);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Address changed.'));
    }

    /** The return screen for a plain account (spec ch 12): they apply, we look at it and call them. Login is required. */
    public function returns(Request $request): Response
    {
        $this->plain($request);
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render('paikari/returns', [
            'portal' => ['base' => '/my-orders', 'bulk' => false, 'moq' => 0, 'payments' => []],
            'memos' => $this->returnService->memosFor($request->user(), $from, $to),
            'filters' => ['from' => $from, 'to' => $to],
            'days' => $this->returnService->windowDays(),
            'credit' => $this->returnService->creditBalance($request->user()),
            'mine' => SaleReturn::where('customer_id', $request->user()->id)->latest('id')->limit(10)->get(['id', 'status', 'amount', 'resolution', 'reason'])
                ->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'amount' => (float) $r->amount, 'resolution' => $r->resolution]),
        ]);
    }

    public function requestReturn(Request $request): RedirectResponse
    {
        $this->plain($request);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.line_id' => ['required', 'integer'],
            'items.*.unit' => ['required', 'in:piece,strip,box'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'reason_code' => ['required', 'in:'.implode(',', ReturnService::REASONS)],
            'reason' => ['required', 'string', 'max:500'],
            'resolution' => ['required', 'in:credit,exchange,cash'],
            'voice' => ['nullable', 'file', 'mimes:mp3,m4a,ogg,wav,webm', 'max:5120'],
        ]);
        $invoice = Invoice::findOrFail($data['invoice_id']);

        if ($invoice->customer_id !== $request->user()->id) {
            throw new AuthorizationException;
        }

        try {
            $this->returnService->request($invoice, $data['items'], $data['reason_code'], $data['reason'], $data['resolution'], $request->user(), null, isset($data['voice']) ? $data['voice']->store('return-voice') : null);
        } catch (StockException $e) {
            return back()->withErrors(['return' => __($e->getMessage())]);
        }

        return back()->with('status', __('Return request sent. We will look at it and call you if we approve.'));
    }

    /** The return screen here is for plain accounts; a shop returns from its own portal. */
    private function plain(Request $request): void
    {
        if ($request->user()?->account_type !== AccountType::Public) {
            throw new AuthorizationException;
        }
    }

    private function digits(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }

    private function find(string $token): Invoice
    {
        return Invoice::where('public_token', $token)->where('channel', 'online')->where('kind', 'retail')->firstOrFail();
    }
}
