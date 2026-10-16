<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Audit\ActivityLogger;
use App\Services\Sales\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Every order and memo in one list with filters (spec ch 25: order management), and settling what a customer still owes. */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('handle-orders');

        $kind = in_array($request->query('kind'), Invoice::KINDS, true) ? $request->query('kind') : null;
        $channel = in_array($request->query('channel'), ['counter', 'online'], true) ? $request->query('channel') : null;
        $status = in_array($request->query('status'), ['placed', 'verified', 'final', 'cancelled'], true) ? $request->query('status') : null;
        $due = $request->boolean('due');
        $q = trim((string) $request->query('q', ''));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $rows = Invoice::query()->with('customer:id,name,phone')->where('status', '!=', 'draft')
            ->when($kind, fn ($w) => $w->where('kind', $kind))->when($channel, fn ($w) => $w->where('channel', $channel))->when($status, fn ($w) => $w->where('status', $status))
            ->when($due, fn ($w) => $w->where('status', 'final')->whereColumn('paid_amount', '<', 'total'))
            ->when($from, fn ($w) => $w->whereDate('opened_at', '>=', $from))->when($to, fn ($w) => $w->whereDate('opened_at', '<=', $to))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $w->where(fn ($x) => $x->where('number', 'like', $like)->orWhere('label', 'like', $like)->orWhere('ship_to', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like)));
            })
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'kind' => $i->kind, 'channel' => $i->channel, 'status' => $i->status,
                'customer' => $i->customer?->name ?? ($i->ship_to['name'] ?? $i->label), 'phone' => $i->customer?->phone ?? ($i->ship_to['phone'] ?? null),
                'total' => $i->status === 'final' ? (float) $i->total : $this->invoices->totals($i)['total'], 'paid' => (float) $i->paid_amount,
                'due' => $i->status === 'final' ? round(max(0, (float) $i->total - (float) $i->paid_amount), 2) : 0.0,
                'when' => ($i->finalised_at ?? $i->placed_at ?? $i->opened_at)?->toIso8601String(),
                'link' => $i->channel === 'online' && $i->status !== 'final' ? "/admin/orders/{$i->id}" : "/admin/counter/{$i->id}",
            ]);

        return Inertia::render('admin/invoices', [
            'rows' => $rows,
            'filters' => ['kind' => $kind, 'channel' => $channel, 'status' => $status, 'due' => $due, 'q' => $q, 'from' => $from, 'to' => $to],
            'owed' => round((float) Invoice::where('status', 'final')->whereColumn('paid_amount', '<', 'total')->selectRaw('sum(total - paid_amount) as owed')->value('owed'), 2),
            'canSettle' => $request->user()->can('view-reports'),
        ]);
    }

    /** What a customer paid later on a memo that was left due. The money only goes up, never past the total, and every payment is logged. */
    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('view-reports');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'method' => ['required', 'in:cash,bkash,nagad,rocket,bank,card']]);

        if ($invoice->status !== 'final') {
            return back()->withErrors(['payment' => __('Only a finished memo can take a payment.')]);
        }

        $owed = round((float) $invoice->total - (float) $invoice->paid_amount, 2);
        if ($owed <= 0) {
            return back()->withErrors(['payment' => __('This memo is already paid.')]);
        }

        if ((float) $data['amount'] > $owed + 0.001) {
            return back()->withErrors(['payment' => __('That is more than is owed.')]);
        }

        $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + (float) $data['amount'], 2)]);
        $this->activity->record('invoice.payment', $invoice, ['amount' => (float) $data['amount'], 'method' => $data['method'], 'owed_before' => $owed], $request->user());

        return back()->with('status', __('Payment saved.'));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
