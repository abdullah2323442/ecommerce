<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Sales\ReturnService;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The return desk. Staff find the old sale and record the return; only the admin approves it (spec ch 12), because
 * approving moves stock and money. Paikari and retail only.
 */
class ReturnController extends Controller
{
    public function __construct(private ReturnService $returns, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('record-returns');
        $admin = request()->user()->can('approve-returns');
        $reports = request()->user()->can('view-reports');

        return Inertia::render('admin/returns', [
            'queue' => SaleReturn::with(['customer:id,name,phone', 'invoice:id,number', 'lines.medicine:id,brand_name,strength'])->where('status', 'requested')->oldest()->get()->map(fn (SaleReturn $r) => $this->card($r)),
            'recent' => SaleReturn::with(['customer:id,name', 'invoice:id,number', 'lines.medicine:id,brand_name,strength'])->where('status', '!=', 'requested')->latest('decided_at')->limit(15)->get()->map(fn (SaleReturn $r) => $this->card($r)),
            'canApprove' => $admin,
            'policy' => ['days' => $this->returns->windowDays(), 'cash_percent' => $this->returns->cashPercent()],
            // Analysis and credit warnings carry money, so staff do not get them (spec ch 26).
            'analysis' => $reports ? $this->analysis() : null,
            'staleCredit' => $reports ? $this->staleCredit() : [],
        ]);
    }

    /** Three ways to find an old sale (spec ch 12): memo number, date, medicine name. */
    public function find(Request $request): JsonResponse
    {
        $this->authorize('record-returns');
        $data = $request->validate(['number' => ['nullable', 'string', 'max:20'], 'date' => ['nullable', 'date'], 'medicine' => ['nullable', 'string', 'max:80']]);

        if (! ($data['number'] ?? null) && ! ($data['date'] ?? null) && ! ($data['medicine'] ?? null)) {
            return response()->json(['results' => []]);
        }

        $q = Invoice::query()->where('status', 'final')->with(['customer:id,name,phone', 'lines.medicine:id,brand_name,strength'])->latest('finalised_at')->limit(15);

        if ($data['number'] ?? null) {
            $q->where('number', 'like', '%'.addcslashes(trim($data['number']), '%_\\').'%');
        }

        if ($data['date'] ?? null) {
            $q->whereDate('finalised_at', $data['date']);
        }

        if ($data['medicine'] ?? null) {
            $like = '%'.addcslashes(trim($data['medicine']), '%_\\').'%';
            $q->whereHas('lines.medicine', fn ($m) => $m->where('brand_name', 'like', $like))->where('finalised_at', '>=', now()->subDays(30));
        }

        return response()->json(['results' => $q->get()->map(fn (Invoice $i) => [
            'id' => $i->id, 'number' => $i->number, 'when' => $i->finalised_at?->toIso8601String(), 'customer' => $i->customer?->name, 'retail' => $i->customer_id === null,
            'reprinted' => $i->print_count > 1,
            'lines' => $i->lines->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'unit' => $l->unit, 'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price, 'offer' => $l->offer_product_id !== null, 'returnable' => $this->returns->returnable($l),
                'previously_returned' => $this->returns->returnedUnits($l) > 0,
            ])->values(),
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('record-returns');
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.line_id' => ['required', 'integer'],
            'items.*.unit' => ['required', 'in:piece,strip,box'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'reason_code' => ['required', 'in:'.implode(',', ReturnService::REASONS)],
            'reason' => ['required', 'string', 'max:500'],
            'resolution' => ['required', 'in:credit,exchange,cash'],
            'expected_money' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $return = $this->returns->request(Invoice::findOrFail($data['invoice_id']), $data['items'], $data['reason_code'], $data['reason'], $data['resolution'], $request->user(), isset($data['expected_money']) ? (float) $data['expected_money'] : null);
        } catch (StockException $e) {
            return back()->withErrors(['return' => __($e->getMessage())]);
        }

        $this->activity->record('return.requested', $return, ['amount' => (string) $return->amount], $request->user());

        return back()->with('status', __('Return recorded. The admin will check and approve it.'));
    }

    public function storeWithoutInvoice(Request $request): RedirectResponse
    {
        $this->authorize('approve-returns');
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'], 'unit' => ['required', 'in:piece,strip,box'], 'quantity' => ['required', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'gt:0'], 'reason_code' => ['required', 'in:'.implode(',', ReturnService::REASONS)], 'reason' => ['required', 'string', 'max:500'],
            'resolution' => ['required', 'in:credit,exchange,cash'], 'customer_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $return = $this->returns->requestWithoutInvoice(Medicine::findOrFail($data['medicine_id']), $data['unit'], (float) $data['quantity'], (float) $data['price'], $data['reason_code'], $data['reason'], $data['resolution'], $request->user(), isset($data['customer_id']) ? User::find($data['customer_id']) : null);
        } catch (StockException $e) {
            return back()->withErrors(['return' => __($e->getMessage())]);
        }

        $this->activity->record('return.without_invoice', $return, ['amount' => (string) $return->amount], $request->user());

        return back()->with('status', __('Return recorded. The admin will check and approve it.'));
    }

    /** The voice note the buyer left with the return, for staff who answer returns. */
    public function voice(SaleReturn $return): StreamedResponse
    {
        $this->authorize('record-returns');
        abort_unless($return->voice_path !== null && Storage::exists($return->voice_path), 404);

        return Storage::response($return->voice_path);
    }

    public function approve(Request $request, SaleReturn $return): RedirectResponse
    {
        $this->authorize('approve-returns');
        $data = $request->validate(['fault' => ['nullable', 'in:staff,shop'], 'note' => ['nullable', 'string', 'max:300']]);

        try {
            $this->returns->approve($return, $request->user(), $data['fault'] ?? null, $data['note'] ?? null);
        } catch (StockException $e) {
            return back()->withErrors(['return' => __($e->getMessage())]);
        }

        $this->activity->record('return.approved', $return, ['fault' => $data['fault'] ?? null], $request->user());

        return back()->with('status', __('Return approved.'));
    }

    public function reject(Request $request, SaleReturn $return): RedirectResponse
    {
        $this->authorize('approve-returns');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        try {
            $this->returns->reject($return, $request->user(), $data['note'] ?? null);
        } catch (StockException $e) {
            return back()->withErrors(['return' => __($e->getMessage())]);
        }

        return back()->with('status', __('Return declined.'));
    }

    private function card(SaleReturn $r): array
    {
        return [
            'id' => $r->id, 'status' => $r->status, 'customer' => $r->customer?->name, 'phone' => $r->customer?->phone, 'memo' => $r->invoice?->number, 'retail' => $r->kind === 'retail',
            'without_invoice' => $r->without_invoice, 'resolution' => $r->resolution, 'reason_code' => $r->reason_code, 'reason' => $r->reason, 'amount' => (float) $r->amount,
            'credit_amount' => (float) $r->credit_amount, 'cash_amount' => (float) $r->cash_amount, 'expected_money' => $r->expected_money !== null ? (float) $r->expected_money : null,
            'has_voice' => $r->voice_path !== null, 'fault' => $r->fault, 'lines' => $r->lines->map(fn (SaleReturnLine $l) => ['name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'unit' => $l->unit, 'quantity' => (float) $l->quantity, 'was_offer' => $l->was_offer])->values(),
        ];
    }

    /** Which products come back most and why, over the last 90 days (spec ch 12). */
    private function analysis(): array
    {
        $since = now()->subDays(90);

        return [
            'reasons' => SaleReturn::where('status', 'approved')->where('decided_at', '>=', $since)->selectRaw('reason_code, count(*) as n, sum(amount) as amount')->groupBy('reason_code')->orderByDesc('n')->get()
                ->map(fn ($r) => ['reason' => $r->reason_code, 'n' => (int) $r->n, 'amount' => (float) $r->amount]),
            'products' => SaleReturnLine::join('sale_returns', 'sale_returns.id', '=', 'sale_return_lines.sale_return_id')->join('medicines', 'medicines.id', '=', 'sale_return_lines.medicine_id')
                ->where('sale_returns.status', 'approved')->where('sale_returns.decided_at', '>=', $since)->groupBy('medicines.id', 'medicines.brand_name')
                ->orderByDesc(DB::raw('count(*)'))->limit(10)->get(['medicines.brand_name as name', DB::raw('count(*) as n')])->map(fn ($r) => ['name' => $r->name, 'n' => (int) $r->n]),
        ];
    }

    /** Credit sitting unused for a long time (setting returns.credit_warn_days, default 45): a warning after 1 to 2 months. */
    private function staleCredit(): array
    {
        $days = (int) Setting::read('returns.credit_warn_days', 45);

        return User::query()->whereHas('creditEntries')->get()->map(function (User $u) {
            $balance = (float) CreditEntry::where('user_id', $u->id)->sum('amount');
            $oldest = CreditEntry::where('user_id', $u->id)->where('amount', '>', 0)->latest('created_at')->value('created_at');

            return ['id' => $u->id, 'name' => $u->name, 'balance' => round($balance, 2), 'days' => $oldest ? (int) Carbon::parse($oldest)->diffInDays(now()) : 0];
        })->filter(fn (array $r) => $r['balance'] > 0 && $r['days'] >= $days)->values()->all();
    }
}
