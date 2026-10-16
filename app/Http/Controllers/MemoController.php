<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\ReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable memo (spec ch 22): A4 or a 58/80 mm thermal receipt. The offer items come first, then the regular
 * items, each with its subtotal, and one grand total at the end. Staff and admin see any memo; a shop only its own.
 * Cost and stock never appear here.
 */
class MemoController extends Controller
{
    public function __construct(private InvoiceService $invoices, private ReturnService $returns) {}

    public function show(Request $request, Invoice $invoice): Response
    {
        $user = $request->user();
        $staff = in_array($user->account_type, [AccountType::Staff, AccountType::Admin], true) && ! $user->isSuspended();

        if (! $staff && ($invoice->customer_id !== $user->id || $invoice->status === 'draft')) {
            throw new AuthorizationException;
        }

        return $this->memo($request, $invoice, $staff);
    }

    /** A buyer without an account opens the memo of a finished order from the private link they were given. */
    public function token(Request $request, string $token): Response
    {
        $invoice = Invoice::where('public_token', $token)->firstOrFail();

        if ($invoice->number === null || ! in_array($invoice->status, ['final', 'cancelled'], true)) {
            throw new AuthorizationException;
        }

        return $this->memo($request, $invoice, false);
    }

    private function memo(Request $request, Invoice $invoice, bool $staff): Response
    {
        $user = $request->user();
        $invoice->load('customer:id,name,phone', 'lines.medicine:id,brand_name,strength,is_cold_chain', 'lines.offer:id');
        $finisher = $invoice->finalised_by ? User::whereKey($invoice->finalised_by)->value('name') : null;

        $final = in_array($invoice->status, ['final', 'cancelled'], true) && $invoice->number !== null;
        $totals = $this->invoices->totals($invoice);

        // The preliminary memo (spec ch 7, step 2): while an order is still open, a shop can see it at the printed prices to get an
        // idea. It is replaced by the final memo at its own price. Offer items are not on it at the printed price: they are at the offer rate.
        $preliminary = $request->boolean('mrp') && ! $final && $invoice->customer_id !== null && $invoice->kind !== 'retail';
        $price = fn (InvoiceLine $l) => $preliminary && $l->offer_product_id === null ? (float) $l->unit_mrp : (float) $l->unit_price;
        $row = fn (InvoiceLine $l) => [
            'name' => $l->medicine->brand_name.($l->rate_layer === 'bonus' ? ' ('.__('Bonus').')' : ''), 'strength' => $l->medicine->strength, 'cold' => (bool) $l->medicine->is_cold_chain, 'unit' => $l->unit, 'quantity' => (float) $l->quantity,
            'unit_price' => $price($l), 'total' => round($price($l) * (float) $l->quantity, 2), 'not_available' => $l->verify_status === 'unavailable',
        ];
        $offerLines = $invoice->lines->filter(fn (InvoiceLine $l) => $l->offer_product_id !== null && (float) $l->quantity > 0)->values();
        $regularLines = $invoice->lines->filter(fn (InvoiceLine $l) => $l->offer_product_id === null)->values();
        $offerSub = round((float) $offerLines->sum(fn ($l) => $price($l) * (float) $l->quantity), 2);
        $regularSub = round((float) $regularLines->sum(fn ($l) => $price($l) * (float) $l->quantity), 2);
        $width = in_array($request->query('w'), ['58', '80', 'a4'], true) ? $request->query('w') : (string) Setting::read('print.width', '80');

        return Inertia::render('memo', [
            'memo' => [
                'id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status, 'when' => ($invoice->finalised_at ?? $invoice->opened_at)?->toIso8601String(),
                'shop' => (string) Setting::read('shop.name', config('app.name')), 'customer' => $invoice->customer?->name ?? ($invoice->ship_to['name'] ?? null), 'phone' => $invoice->customer?->phone ?? ($invoice->ship_to['phone'] ?? null),
                'staff' => $finisher, 'reprint' => $invoice->print_count > 1, 'payment_method' => $invoice->payment_method,
                'offer' => ['lines' => $offerLines->map($row), 'subtotal' => $offerSub],
                'regular' => ['lines' => $regularLines->map($row), 'subtotal' => $regularSub],
                'preliminary' => $preliminary,
                'discount' => $preliminary ? 0.0 : ($final ? (float) $invoice->discount_total : $totals['discount']), 'delivery' => $preliminary ? 0.0 : (float) $invoice->delivery_charge, 'delivery_extra' => $preliminary ? 0.0 : (float) $invoice->delivery_extra, 'credit' => $preliminary ? 0.0 : (float) $invoice->credit_applied, 'token' => $preliminary ? 0.0 : (float) $invoice->token_applied,
                'total' => $preliminary ? round($offerSub + $regularSub, 2) : ($final ? (float) $invoice->total : $totals['total']), 'paid' => (float) $invoice->paid_amount, 'cancelled' => $invoice->status === 'cancelled',
            ],
            'policy' => ['days' => $this->returns->windowDays(), 'cash_percent' => $this->returns->cashPercent()],
            'width' => $width,
            'canPrint' => $staff,
            'isAdmin' => $staff && $user?->account_type === AccountType::Admin,
        ]);
    }
}
