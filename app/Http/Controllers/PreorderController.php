<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Preorder;
use App\Models\PreorderLine;
use App\Services\Analytics\Behavior;
use App\Services\Sales\PreOrders;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The buyer's side of pre-order (spec ch 21): ask for a sold-out product with a promised time, follow the draft memo,
 * and cancel. A shop, a bulk buyer and a plain account all use the same screens. The buyer never sees stock.
 */
class PreorderController extends Controller
{
    public function __construct(private PreOrders $preorders, private Behavior $behavior) {}

    public function index(Request $request): Response
    {
        $this->customer($request);

        return Inertia::render('preorders', [
            'items' => Preorder::where('customer_id', $request->user()->id)->with('lines.medicine:id,brand_name,strength')->latest('id')->limit(40)->get()
                ->map(fn (Preorder $p) => $this->row($p)),
            'notices' => $this->notices($request),
            'home' => $request->user()->account_type->homePath(),
        ]);
    }

    public function show(Request $request, Preorder $preorder): Response
    {
        $this->customer($request);
        $this->owns($request, $preorder);
        $preorder->load('lines.medicine:id,brand_name,strength,company_id', 'lines.medicine.company:id,name', 'invoice:id,public_token,number,status');

        return Inertia::render('preorder-show', [
            'preorder' => $this->row($preorder) + [
                'lines' => $preorder->lines->map(fn (PreorderLine $l) => [
                    'id' => $l->id, 'name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'company' => $l->medicine->company?->name, 'unit' => $l->unit,
                    'quantity' => (float) $l->quantity, 'received' => $l->received_quantity === null ? null : (float) $l->received_quantity,
                    'unit_price' => $this->preorders->unitPrice($preorder, $l),
                ])->values(),
                'total' => $this->preorders->expectedTotal($preorder),
                'reply_note' => $preorder->reply_note, 'cancel_note' => $preorder->cancel_note,
                'order' => $preorder->invoice ? ['id' => $preorder->invoice->id, 'number' => $preorder->invoice->number, 'token' => $preorder->invoice->public_token] : null,
            ],
            'home' => $request->user()->account_type->homePath(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->customer($request);
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'unit' => ['required', 'in:piece,strip,box'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        try {
            $pre = $this->preorders->request($request->user(), Medicine::with('company')->findOrFail($data['medicine_id']), $data['unit'], (float) $data['quantity']);
        } catch (StockException $e) {
            return back()->withErrors(['preorder' => __($e->getMessage())]);
        }

        $this->behavior->record('preorder', (int) $data['medicine_id'], null, ['unit' => $data['unit']]);

        return redirect()->route('preorders.show', $pre)->with('status', __('Pre-order sent. We will tell you if we accept it.'));
    }

    public function cancel(Request $request, Preorder $preorder): RedirectResponse
    {
        $this->customer($request);
        $this->owns($request, $preorder);

        try {
            $this->preorders->cancelByBuyer($preorder);
        } catch (StockException $e) {
            return back()->withErrors(['preorder' => __($e->getMessage())]);
        }

        return back()->with('status', __('Pre-order cancelled.'));
    }

    public function readNotice(Request $request, Notice $notice): RedirectResponse
    {
        if ($notice->user_id !== $request->user()?->id) {
            throw new AuthorizationException;
        }
        $notice->update(['read_at' => now()]);

        return back();
    }

    private function row(Preorder $p): array
    {
        $p->loadMissing('lines.medicine:id,brand_name,strength');
        $first = $p->lines->first();

        return [
            'id' => $p->id, 'status' => $p->status, 'late' => $p->isLate(), 'due_on' => $p->due_on?->toDateString(), 'days' => (int) $p->promised_days,
            'name' => $first ? trim($first->medicine->brand_name.' '.$first->medicine->strength) : '', 'quantity' => $first ? (float) $first->quantity : 0, 'unit' => $first?->unit,
            'advance' => (float) $p->advance_amount, 'advance_state' => $p->advance_state, 'created_at' => $p->created_at?->toIso8601String(), 'open' => $p->isOpen(),
        ];
    }

    private function notices(Request $request)
    {
        return Notice::where('user_id', $request->user()->id)->where('kind', 'like', 'preorder_%')->whereNull('read_at')->latest('id')->get(['id', 'kind', 'body']);
    }

    private function customer(Request $request): void
    {
        if (! in_array($request->user()?->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }
    }

    private function owns(Request $request, Preorder $preorder): void
    {
        if ($preorder->customer_id !== $request->user()->id) {
            throw new AuthorizationException;
        }
    }
}
