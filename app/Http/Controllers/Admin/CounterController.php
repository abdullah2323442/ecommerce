<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\OfferProduct;
use App\Models\QuickNote;
use App\Models\Setting;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\ApprovalService;
use App\Services\Sales\ControlledRegister;
use App\Services\Audit\ActivityLogger;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\OfferProducts;
use App\Services\Sales\ReturnService;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The counter (spec 4.3.5): open drafts, the draft screen and the memo. Staff and admin only. No screen here
 * reads stock or purchase figures; the only stock feedback is the refusal text when something is not there.
 */
class CounterController extends Controller
{
    public function __construct(private InvoiceService $invoices, private OfferProducts $offerProducts, private ReturnService $returns, private ControlledRegister $controlled) {}

    public function index(): Response
    {
        $this->authorize('use-counter');

        $open = Invoice::query()->where('status', 'draft')->with('customer:id,name,phone', 'openedBy:id,name')->withCount('lines')->orderBy('opened_at')->get();
        // "This shop has 2 drafts open" (DRF-5): how many open drafts each customer has.
        $perShop = $open->whereNotNull('customer_id')->countBy('customer_id');
        $drafts = $open->map(fn (Invoice $i) => $this->summary($i) + ['same_customer' => $i->customer_id ? (int) $perShop[$i->customer_id] : 1]);

        return Inertia::render('admin/counter/index', [
            'drafts' => $drafts,
            'byStaff' => $open->groupBy(fn (Invoice $i) => $i->openedBy?->name ?? '—')->map->count()->sortDesc()->map(fn ($n, $name) => ['name' => $name, 'drafts' => $n])->values(),
            'notes' => QuickNote::whereNull('done_at')->with('customer:id,name,phone')->latest('id')->get()
                ->map(fn (QuickNote $n) => ['id' => $n->id, 'note' => $n->note, 'customer' => $n->customer?->name, 'phone' => $n->customer?->phone, 'minutes' => (int) $n->created_at->diffInMinutes(now())]),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate(['kind' => ['required', 'in:retail,paikari,wholesale'], 'customer_id' => ['nullable', 'integer', 'exists:users,id']]);

        $customer = isset($data['customer_id']) ? User::find($data['customer_id']) : null;
        if ($customer && ! in_array($customer->account_type, [AccountType::Paikari, AccountType::Wholesaler], true)) {
            return back()->withErrors(['customer_id' => __('Pick a shop customer.')]);
        }

        try {
            $invoice = $this->invoices->open($data['kind'], $request->user(), $customer);
        } catch (StockException $e) {
            return back()->withErrors(['customer_id' => __($e->getMessage())]);
        }

        return redirect()->route('admin.counter.show', $invoice);
    }

    /**
     * Quick profile at the counter (spec 4.3.5): name, phone, area. The shop gets the default tier for now and the
     * admin sets its real rate later. The account cannot be signed into until the shop sets its own password.
     */
    public function newShop(Request $request): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'area' => ['required', 'string', 'max:80'],
        ]);

        $tier = Tier::find(Setting::read('counter.default_tier_id')) ?? Tier::orderBy('discount_percent')->first();

        // The account has no email yet (the column is required), so a placeholder that can never receive mail stands in until the shop sets its own.
        $shop = new User(['name' => $data['name'], 'phone' => $data['phone'], 'area' => $data['area'], 'email' => $data['phone'].'@phone.invalid', 'password' => Hash::make(Str::random(40))]);
        $shop->forceFill(['account_type' => AccountType::Paikari, 'tier_id' => $tier?->id])->save();

        $invoice = $this->invoices->open('paikari', $request->user(), $shop);

        return redirect()->route('admin.counter.show', $invoice)->with('status', __('Shop added. It has the default rate until the admin sets its own.'));
    }

    /** Scanning a box adds one to the open draft; scanning again adds another (spec 4.3.5, 6.10). */
    public function scan(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'medicine_id' => ['nullable', 'integer', 'exists:medicines,id']]);
        $code = MedicineBarcode::clean($data['code']);
        $barcode = MedicineBarcode::with('medicine')->where('code', $code)->first();

        // An unknown code is asked about, not refused (spec 6.10): the person says which medicine it is and it is remembered for next time.
        if (! $barcode && isset($data['medicine_id']) && $code !== '') {
            $barcode = MedicineBarcode::create(['medicine_id' => $data['medicine_id'], 'code' => $code])->load('medicine');
            app(ActivityLogger::class)->record('barcode.linked', $barcode->medicine, ['code' => $code], $request->user());
        }

        if (! $barcode) {
            return back()->withErrors(['invoice' => __('This barcode is not linked to a medicine yet.'), 'unknown_barcode' => $code]);
        }

        $unit = $barcode->medicine->packaging_type === 'box' ? 'box' : 'piece';

        return $this->attempt(fn () => $this->invoices->addLine($invoice, $barcode->medicine, $unit, 1.0, $request->user()));
    }

    /** Jot a phone order under the shop's name so it is not forgotten (spec 4.4.7). */
    public function storeNote(Request $request): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate(['customer_id' => ['required', 'integer', 'exists:users,id'], 'note' => ['required', 'string', 'max:500']]);
        $customer = User::findOrFail($data['customer_id']);

        if (! in_array($customer->account_type, [AccountType::Paikari, AccountType::Wholesaler], true)) {
            return back()->withErrors(['customer_id' => __('Pick a shop customer.')]);
        }

        QuickNote::create(['customer_id' => $customer->id, 'note' => $data['note'], 'created_by' => $request->user()->id]);

        return back()->with('status', __('Note saved.'));
    }

    /** Turn a note into a draft for that shop, at its own prices; the note is done and keeps the link. */
    public function noteToDraft(Request $request, QuickNote $note): RedirectResponse
    {
        $this->authorize('use-counter');
        abort_if($note->done_at !== null, 404);
        $customer = $note->customer;
        $kind = $customer->account_type === AccountType::Wholesaler ? 'wholesale' : 'paikari';

        $invoice = $this->invoices->open($kind, $request->user(), $customer);
        $invoice->update(['note' => $note->note]);
        $note->update(['invoice_id' => $invoice->id, 'done_at' => now()]);

        return redirect()->route('admin.counter.show', $invoice);
    }

    public function show(Invoice $invoice): Response
    {
        $this->authorize('use-counter');
        $invoice->load('customer:id,name,phone', 'lines.medicine.company');

        return Inertia::render('admin/counter/show', [
            'invoice' => $this->detail($invoice),
            'controlled' => $this->controlled->panel($invoice),
            'controlledDefaults' => $invoice->kind === 'retail' ? null : ['buyer_name' => $invoice->customer?->name, 'buyer_phone' => $invoice->customer?->phone],
            'otherDrafts' => Invoice::where('status', 'draft')->where('id', '!=', $invoice->id)->where('kind', $invoice->kind)
                ->orderBy('opened_at')->get(['id', 'label'])
                ->map(fn ($i) => ['id' => $i->id, 'label' => $i->label ?? __('Draft').' #'.$i->id]),
            'limit' => (float) Setting::read('counter.staff_discount_limit', 5),
            'policy' => ['days' => $this->returns->windowDays(), 'cash_percent' => $this->returns->cashPercent()],
            'approvals' => $this->approvalsFor($invoice),
            'popular' => $this->popular(),
            'isAdmin' => request()->user()->can('decide-approvals'),
        ]);
    }

    /** Medicine search for the draft screen: name, pack and the price each unit would have on this invoice. */
    public function search(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('use-counter');
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $found = Medicine::query()->with('company:id,name')
            ->where('brand_name', 'like', addcslashes($q, '%_\\').'%')
            // In-stock medicines first, the rest after (spec 6.10); then by name.
            ->orderByDesc(\App\Models\StockLot::query()->select(\Illuminate\Support\Facades\DB::raw('1'))->whereColumn('stock_lots.medicine_id', 'medicines.id')->where('units_remaining', '>', 0)->limit(1))
            ->orderBy('brand_name')->limit(12)->get();

        $offers = $invoice->kind === 'retail' ? collect() : $this->offerProducts->visibleTo('paikari', true)->keyBy('medicine_id'); // no offers at retail; staff see every one

        return response()->json(['results' => $found->map(function (Medicine $m) use ($invoice, $offers, $found) {
            $units = $m->packaging_type === 'box' ? ['box', 'strip', 'piece'] : ['piece'];

            return [
                'id' => $m->id, 'name' => $m->brand_name, 'strength' => $m->strength, 'form' => $m->form, 'company' => $m->company?->name,
                'offer' => $offers->has($m->id) ? $this->invoices->offerCard($invoice, $offers[$m->id]) : null,
                'similar' => $found->contains(fn (Medicine $o) => $o->id !== $m->id && $o->brand_name !== $m->brand_name && levenshtein(mb_strtolower($o->brand_name), mb_strtolower($m->brand_name)) <= 2),
                'units' => collect($units)->map(fn ($u) => ['unit' => $u] + collect($this->invoices->rate($invoice, $m, $u))->only(['mrp', 'price', 'percent'])->all())->values(),
            ];
        })]);
    }

    /** Find a shop customer by name or phone; shows any order already open for them (DRF-3). */
    public function customers(Request $request): JsonResponse
    {
        $this->authorize('use-counter');
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';

        $users = User::query()->whereIn('account_type', [AccountType::Paikari->value, AccountType::Wholesaler->value])
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('area', 'like', $like))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'phone', 'area', 'account_type']);
        $open = Invoice::where('status', 'draft')->whereIn('customer_id', $users->pluck('id'))->pluck('id', 'customer_id');

        return response()->json(['results' => $users->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'area' => $u->area, 'kind' => $u->account_type === AccountType::Wholesaler ? 'wholesale' : 'paikari',
            'open_invoice' => $open[$u->id] ?? null,
        ])]);
    }

    public function addLine(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'unit' => ['required', 'in:piece,strip,box'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'special_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'outside' => ['sometimes', 'boolean'],
            'separate' => ['sometimes', 'boolean'],
        ]);

        $medicine = Medicine::findOrFail($data['medicine_id']);
        // The same medicine in the same pack is added to its line (DRF-8); say so, so nobody thinks it was a second line (DRF-9).
        $again = ! ($data['separate'] ?? false) && ! ($data['outside'] ?? false)
            && $invoice->lines()->where('medicine_id', $medicine->id)->where('unit', $data['unit'])->where('is_outside', false)->exists();

        $response = $this->attempt(fn () => $this->invoices->addLine(
            $invoice, $medicine, $data['unit'], (float) $data['quantity'], $request->user(),
            isset($data['special_percent']) ? (float) $data['special_percent'] : null, (bool) ($data['outside'] ?? false), (bool) ($data['separate'] ?? false),
        ));

        return $again && ! session()->has('errors')
            ? $response->with('status', __(':name was already on this sale, so the amount was added to it.', ['name' => trim($medicine->brand_name.' '.$medicine->strength)]))
            : $response;
    }

    public function addOffer(Request $request, Invoice $invoice, OfferProduct $offer): RedirectResponse
    {
        $this->authorize('use-counter');
        abort_if($invoice->kind === 'retail', 404);
        $data = $request->validate(['boxes' => ['required', 'integer', 'min:1', 'max:100000']]);

        return $this->attempt(fn () => $this->invoices->addOfferLine($invoice, $offer, (int) $data['boxes'], $request->user()));
    }

    public function setQuantity(Request $request, Invoice $invoice, InvoiceLine $line): RedirectResponse
    {
        $this->authorize('use-counter');
        abort_unless($line->invoice_id === $invoice->id, 404);
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0', 'max:100000']]);

        return $this->attempt(fn () => $this->invoices->setQuantity($line, (float) $data['quantity'], $request->user()));
    }

    /** Move one line to another open draft (DRF-7), from the line's own menu. */
    public function moveLine(Request $request, Invoice $invoice, InvoiceLine $line): RedirectResponse
    {
        $this->authorize('use-counter');
        abort_unless($line->invoice_id === $invoice->id, 404);
        $data = $request->validate(['to' => ['required', 'integer', 'exists:invoices,id']]);
        $to = Invoice::findOrFail($data['to']);

        if ($to->id === $invoice->id || $to->kind !== $invoice->kind) {
            return back()->withErrors(['invoice' => __('Pick another open draft of the same kind.')]);
        }

        return $this->attempt(fn () => $this->invoices->moveLine($line->load('invoice'), $to), 'Moved to the other draft.');
    }

    public function removeLine(Request $request, Invoice $invoice, InvoiceLine $line): RedirectResponse
    {
        $this->authorize('use-counter');
        abort_unless($line->invoice_id === $invoice->id, 404);

        return $this->attempt(fn () => $this->invoices->removeLine($line, $request->user()));
    }

    public function discount(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate(['percent' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $percent = isset($data['percent']) ? (float) $data['percent'] : null;

        return $this->attempt(fn () => $invoice->kind === 'retail'
            ? $this->invoices->setEndDiscount($invoice, $percent ?? 0.0, $request->user())
            : $this->invoices->setInvoicePercent($invoice, $percent, $request->user()));
    }

    /** A line the invoice's percent would sell at a loss gets a lower percent of its own. */
    public function linePercent(Request $request, Invoice $invoice, InvoiceLine $line): RedirectResponse
    {
        $this->authorize('use-counter');
        abort_unless($line->invoice_id === $invoice->id, 404);
        $data = $request->validate(['percent' => ['required', 'numeric', 'min:0', 'max:99.99']]);

        return $this->attempt(fn () => $this->invoices->setLinePercent($line, (float) $data['percent'], $request->user()));
    }

    /** Change the customer of a draft. */
    public function customer(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate(['customer_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->attempt(fn () => $this->invoices->changeCustomer($invoice, User::findOrFail($data['customer_id']), $request->user()));
    }

    /** "Keep" on a draft that has sat for a day or two. */
    public function keep(Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');

        return $this->attempt(fn () => $this->invoices->keepOpen($invoice));
    }

    public function merge(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');
        $data = $request->validate(['source_id' => ['required', 'integer', 'exists:invoices,id']]);

        return $this->attempt(fn () => $this->invoices->merge($invoice, Invoice::findOrFail($data['source_id'])));
    }

    /** Counts a print; from the second one the memo says "Reprint" so one memo is not returned twice (spec ch 12). */
    public function printed(Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');
        abort_unless($invoice->status === 'final', 404);
        $invoice->increment('print_count');

        return back();
    }

    /** The printer width the counter uses by default: 58 mm, 80 mm or A4 (spec ch 22). */
    public function printWidth(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');
        $data = $request->validate(['width' => ['required', 'in:58,80,a4']]);
        Setting::write('print.width', $data['width']);

        return back()->with('status', __('Saved.'));
    }

    /** Admin only: the old memo stays as Cancelled, a new draft opens with its lines. */
    public function correct(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('manage-stock');

        try {
            $new = $this->invoices->correct($invoice, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['invoice' => __($e->getMessage())]);
        }

        return redirect()->route('admin.counter.show', $new)->with('status', __('The old memo is cancelled. Fix this one and finish it for the new number.'));
    }

    public function finalise(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');

        $data = $request->validate([
            'payment_method' => ['nullable', 'in:'.implode(',', InvoiceService::PAYMENT_METHODS)],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->attempt(fn () => $this->invoices->finalise(
            $invoice, $request->user(), $data['payment_method'] ?? 'cash', isset($data['paid_amount']) ? (float) $data['paid_amount'] : null,
        ), 'Sale finished.');
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('use-counter');

        try {
            $this->invoices->cancel($invoice, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['invoice' => __($e->getMessage())]);
        }

        return redirect()->route('admin.counter.index')->with('status', __('Draft cancelled.'));
    }

    /** The medicines sold most in the last 30 days, one click away (spec 4.3.5). */
    private function popular(): array
    {
        return DB::table('invoice_lines')->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')->join('medicines', 'medicines.id', '=', 'invoice_lines.medicine_id')
            ->where('invoices.status', 'final')->where('invoices.finalised_at', '>=', now()->subDays(30))->whereNull('invoice_lines.removed_at')
            ->groupBy('medicines.id', 'medicines.brand_name', 'medicines.strength')->orderByDesc(DB::raw('count(*)'))->limit(8)
            ->get(['medicines.id', 'medicines.brand_name as name', 'medicines.strength'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'strength' => $r->strength])->all();
    }

    /** The latest answer on each kind of request for this invoice, so the staff screen shows waiting, yes, no or timed out. */
    private function approvalsFor(Invoice $invoice): array
    {
        app(ApprovalService::class)->expireOld();

        return ApprovalRequest::where('invoice_id', $invoice->id)->latest('id')->limit(6)->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'value' => (float) $r->value, 'status' => $r->status, 'applied' => $r->applied])->all();
    }

    private function attempt(callable $do, ?string $done = null): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['invoice' => __($e->getMessage())]);
        }

        return $done ? back()->with('status', __($done)) : back();
    }

    private function summary(Invoice $i): array
    {
        return [
            'id' => $i->id, 'kind' => $i->kind, 'label' => $i->label, 'customer' => $i->customer?->name, 'phone' => $i->customer?->phone,
            'lines' => $i->lines_count ?? $i->lines()->count(), 'channel' => $i->channel,
            'minutes_open' => (int) $i->opened_at->diffInMinutes(now()),
            'stale' => $this->invoices->isStale($i), 'staff' => $i->openedBy?->name,
        ];
    }

    private function detail(Invoice $i): array
    {
        return $this->summary($i) + [
            'status' => $i->status, 'number' => $i->number, 'corrects' => $i->corrects_invoice_id ? Invoice::whereKey($i->corrects_invoice_id)->value('number') : null, 'can_correct' => $i->status === 'final' && request()->user()?->can('manage-stock'), 'note' => $i->note, 'credit_applied' => (float) $i->credit_applied, 'print_count' => (int) $i->print_count, 'payment_method' => $i->payment_method, 'paid_amount' => (float) $i->paid_amount, 'invoice_percent' => $i->invoice_percent, 'end_discount_percent' => $i->end_discount_percent,
            'totals' => $this->invoices->totals($i), 'finalised_at' => $i->finalised_at?->toIso8601String(),
            'lines_detail' => $i->lines->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'company' => $l->medicine->company?->name,
                'unit' => $l->unit, 'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'unit_mrp' => (float) $l->unit_mrp,
                'offer' => $l->offer_product_id !== null, 'percent_off' => (float) $l->percent_off, 'special' => $l->special_percent !== null, 'held' => (bool) $l->held_for_loss, 'outside' => $l->is_outside, 'total' => $l->lineTotal(),
            ])->values(),
        ];
    }
}
