<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Sales\ApprovalService;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "Need admin approval": staff ask from the counter, the admin answers with one tap (spec 6.2). */
class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function index(): Response
    {
        $this->authorize('decide-approvals');
        $this->approvals->expireOld();

        $card = fn (ApprovalRequest $r) => [
            'id' => $r->id, 'kind' => $r->kind, 'value' => (float) $r->value, 'status' => $r->status, 'applied' => $r->applied, 'staff' => $r->requester?->name,
            'invoice_id' => $r->invoice_id, 'who' => $r->invoice?->customer?->name ?? $r->invoice?->label, 'item' => $r->line?->medicine?->brand_name,
            'minutes_left' => max(0, (int) now()->diffInMinutes($r->expires_at, false)),
        ];

        return Inertia::render('admin/approvals', [
            'pending' => ApprovalRequest::with(['requester:id,name', 'invoice.customer:id,name', 'line.medicine:id,brand_name'])->where('status', 'pending')->oldest()->get()->map($card),
            'recent' => ApprovalRequest::with(['requester:id,name', 'invoice.customer:id,name', 'line.medicine:id,brand_name'])->where('status', '!=', 'pending')->latest('decided_at')->limit(15)->get()->map($card),
            'minutes' => $this->approvals->minutes(),
        ]);
    }

    /** Staff ask. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'kind' => ['required', 'in:'.implode(',', ApprovalService::KINDS)],
            'value' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'line_id' => ['nullable', 'integer'],
        ]);
        $invoice = Invoice::findOrFail($data['invoice_id']);
        $line = isset($data['line_id']) ? InvoiceLine::where('invoice_id', $invoice->id)->findOrFail($data['line_id']) : null;

        try {
            $this->approvals->request($invoice, $data['kind'], (float) $data['value'], $request->user(), $line);
        } catch (StockException $e) {
            return back()->withErrors(['invoice' => __($e->getMessage())]);
        }

        return back()->with('status', __('Sent to the admin. Waiting for a yes or no.'));
    }

    public function approve(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('decide-approvals');

        try {
            $this->approvals->approve($approval, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['approval' => __($e->getMessage())]);
        }

        return back()->with('status', __('Approved.'));
    }

    public function reject(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->authorize('decide-approvals');

        try {
            $this->approvals->reject($approval, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['approval' => __($e->getMessage())]);
        }

        return back()->with('status', __('Declined.'));
    }
}
