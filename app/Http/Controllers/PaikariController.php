<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\OfferProduct;
use App\Models\Prescription;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\ShortListItem;
use App\Models\StockLot;
use App\Services\Media\ImageCompressor;
use App\Services\Mpo\MpoService;
use App\Services\Sales\Dispatching;
use App\Services\Sales\BulkOrder;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\OfferProducts;
use App\Services\Sales\OrderHabits;
use App\Services\Sales\OrderNotes;
use App\Services\Sales\OrderNotice;
use App\Services\Sales\PreOrders;
use App\Services\Sales\ReturnService;
use App\Services\Sales\TierUpgrade;
use App\Services\Sales\TradeTerms;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop buyer's side of an order (spec 4.4). A shop sees its own prices and its own orders and nothing
 * about stock, ever: a line that cannot be supplied only shows up after staff have checked it.
 */
class PaikariController extends Controller
{
    public function __construct(private InvoiceService $invoices, private OrderNotice $notice, private TierUpgrade $upgrade, private OfferProducts $offerProducts, private ReturnService $returnService, private TradeTerms $terms, private BulkOrder $bulk) {}

    public function index(Request $request): Response
    {
        $this->buyer($request);
        $cart = $this->cart($request, false);

        return Inertia::render('paikari/index', [
            'portal' => $this->portal($request),
            'bulkResult' => session('bulk'),
            'cart' => $cart ? $this->detail($cart->load('lines.medicine.company')) : null,
            'freeOver' => (float) Setting::read('delivery.free_over', 0),
            'notice' => $this->notice->at(),
            'offers' => $this->offerProducts->visibleTo($this->kind($request))->map(fn (OfferProduct $o) => $this->invoices->offerCard($this->probe($request), $o))->values(),
            'usual' => $this->usual($request),
            'companies' => $request->user()->hasCategory('medicine')
                ? Company::whereHas('medicines', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(['id', 'name']) : [],
            'reminder' => ['on' => (bool) $request->user()->habit_reminders, 'at' => app(OrderHabits::class)->enabled() ? app(OrderHabits::class)->usualTime($request->user()) : null],
            // Only at month end: nothing live during the month, so nobody buys just to climb a tier.
            'monthTotal' => $request->user()->show_monthly_total && $this->upgrade->inWindow() ? $this->upgrade->monthTotal($request->user()) : null,
            'tierTask' => $this->upgrade->inWindow() ? $this->upgrade->task($request->user()->load('tier')) : null,
            'notices' => Notice::where('user_id', $request->user()->id)->whereIn('kind', ['tier_up', 'application_approved'])->whereNull('read_at')->latest('id')->get(['id', 'kind', 'body']),
            'shortList' => ShortListItem::where('user_id', $request->user()->id)->with('medicine.company')->latest('id')->get()
                ->map(fn (ShortListItem $s) => ['id' => $s->id, 'name' => $s->medicine->brand_name, 'strength' => $s->medicine->strength, 'company' => $s->medicine->company?->name, 'unit' => $s->unit, 'quantity' => (float) $s->quantity]),
            // Online orders, and finished memos made at the counter: the sale is on their page either way.
            'orders' => Invoice::where('customer_id', $request->user()->id)->where('status', '!=', 'draft')->where(fn ($q) => $q->where('channel', 'online')->orWhere('status', 'final'))
                ->latest('id')->limit(20)->get()->map(fn (Invoice $i) => ['id' => $i->id, 'status' => $i->status, 'number' => $i->number, 'total' => (float) $i->total, 'placed_at' => $i->placed_at?->toIso8601String()]),
        ]);
    }

    /** Turn the "time to order" reminder on or off (spec ch 7). */
    public function reminders(Request $request): RedirectResponse
    {
        $this->buyer($request);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $request->user()->forceFill(['habit_reminders' => (bool) $data['on']])->save();

        return back()->with('status', $data['on'] ? __('Reminders are on.') : __('Reminders are off.'));
    }

    public function search(Request $request): JsonResponse
    {
        $this->buyer($request);
        $q = trim((string) $request->query('q', ''));
        // "By company" (spec 29-A): every product of one company at once, for shops that order company by company.
        $companyId = (int) $request->query('company', 0);
        if (mb_strlen($q) < 2 && $companyId === 0) {
            return response()->json(['results' => []]);
        }

        // A buyer who was not given the medicine category sees no medicine at all, not even that it exists.
        if (! $request->user()->hasCategory('medicine')) {
            return response()->json(['results' => []]);
        }

        $probe = $this->probe($request);
        $found = Medicine::query()->with('company:id,name,preorder_days')->where('is_active', true)
            ->when($companyId > 0, fn ($b) => $b->where('company_id', $companyId), fn ($b) => $b->where('brand_name', 'like', addcslashes($q, '%_\\').'%'))
            // In-stock medicines first, the rest after (spec 6.10); then by name.
            ->orderByDesc(StockLot::query()->select(DB::raw('1'))->whereColumn('stock_lots.medicine_id', 'medicines.id')->where('units_remaining', '>', 0)->limit(1))
            ->orderBy('brand_name')->orderBy('id')->when($companyId > 0, fn ($b) => $b->skip((max(1, (int) $request->query('page', 1)) - 1) * 40)->limit(41), fn ($b) => $b->limit(12))->get();
        // One more than a page is read, only to know whether there is a next page.
        $more = $companyId > 0 && $found->count() > 40;
        $found = $found->take($companyId > 0 ? 40 : 12);

        $offers = $this->offerProducts->visibleTo($this->kind($request))->keyBy('medicine_id');

        return response()->json(['more' => $more, 'results' => $found->map(fn (Medicine $m) => [
            'id' => $m->id, 'name' => $m->brand_name, 'strength' => $m->strength, 'form' => $m->form, 'company' => $m->company?->name,
            'preorder_days' => app(PreOrders::class)->daysFor($m),
            // The same medicine from other brands, the best price first (spec ch 11): shops take whichever is cheaper or more discounted.
            'alternatives' => $this->alternatives($m, $probe, $offers),
            'mpo_offers' => app(MpoService::class)->offersFor($m, $this->kind($request) === 'wholesale' ? 'wholesale' : 'paikari', $request->user()),
            'offer' => $offers->has($m->id) ? $this->invoices->offerCard($probe, $offers[$m->id]) : null,
            'units' => collect($m->packaging_type === 'box' ? ['box', 'strip', 'piece'] : ['piece'])
                ->map(fn ($u) => ['unit' => $u] + collect($this->invoices->rate($probe, $m, $u))->only(['mrp', 'price', 'percent'])->all())->values(),
        ])]);
    }

    /**
     * Other brands of the same generic that are in stock and offered or discounted, the most discounted first (spec ch 11). A medicine with no
     * generic written has none. Shown to a shop at its own price, never the stock numbers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function alternatives(Medicine $m, Invoice $probe, $offers): array
    {
        if (blank($m->generic_name)) {
            return [];
        }

        return Medicine::query()->with('company:id,name')->where('is_active', true)->where('generic_name', $m->generic_name)->where('id', '!=', $m->id)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('stock_lots')->whereColumn('stock_lots.medicine_id', 'medicines.id')->where('units_remaining', '>', 0))
            ->limit(20)->get()
            ->map(function (Medicine $o) use ($probe, $offers) {
                $unit = $o->packaging_type === 'box' ? 'box' : 'piece';
                $rate = $this->invoices->rate($probe, $o, $unit);
                $offer = $offers->has($o->id);

                return ['id' => $o->id, 'name' => $o->brand_name, 'strength' => $o->strength, 'company' => $o->company?->name, 'unit' => $unit, 'price' => $rate['price'], 'percent' => $rate['percent'], 'offer' => $offer];
            })
            ->filter(fn (array $a) => $a['offer'] || $a['percent'] > 0)
            ->sortBy([['offer', 'desc'], ['percent', 'desc'], ['name', 'asc']])->take(3)->values()->all();
    }

    public function addLine(Request $request): RedirectResponse
    {
        $this->buyer($request);
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'unit' => ['required', 'in:piece,strip,box'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        $this->category($request);

        return $this->attempt(fn () => $this->invoices->addLine($this->cart($request, true), Medicine::findOrFail($data['medicine_id']), $data['unit'], (float) $data['quantity'], $request->user()));
    }

    /** Upload a list of what to order (spec ch 14): matched lines go into the order to read through; the rest are listed with the reason. */
    public function bulk(Request $request): RedirectResponse
    {
        $this->buyer($request);
        abort_unless($request->user()->account_type === AccountType::Wholesaler, 403);
        $this->category($request);
        $request->validate(['list' => ['required', 'file', 'mimes:csv,txt', 'max:200']]);

        try {
            $result = $this->bulk->import($this->cart($request, true), $request->user(), (string) file_get_contents($request->file('list')->getRealPath()));
        } catch (StockException $e) {
            return back()->withErrors(['bulk' => __($e->getMessage())]);
        }

        return back()->with('bulk', $result);
    }

    /** Order boxes from an offer. Under the minimum it becomes an ordinary line at the shop's normal rate. */
    public function addOffer(Request $request, OfferProduct $offer): RedirectResponse
    {
        $this->buyer($request);
        abort_unless(in_array($this->kind($request), (array) $offer->visible_to, true), 404);
        $data = $request->validate(['boxes' => ['required', 'integer', 'min:1', 'max:100000']]);

        try {
            $this->invoices->addOfferLine($this->cart($request, true), $offer, (int) $data['boxes'], $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return $data['boxes'] < $offer->min_boxes
            ? back()->with('status', __('The offer needs at least {n} boxes, so this is at your normal rate.', ['n' => $offer->min_boxes]))
            : back();
    }

    public function setQuantity(Request $request, InvoiceLine $line): RedirectResponse
    {
        $this->owned($request, $line->invoice);
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0', 'max:100000']]);

        return $this->attempt(fn () => $this->invoices->setQuantity($line, (float) $data['quantity'], $request->user()));
    }

    public function removeLine(Request $request, InvoiceLine $line): RedirectResponse
    {
        $this->owned($request, $line->invoice);

        return $this->attempt(fn () => $this->invoices->removeLine($line, $request->user()));
    }

    public function remember(Request $request): RedirectResponse
    {
        $this->buyer($request);
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'unit' => ['required', 'in:piece,strip,box'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        ShortListItem::updateOrCreate(
            ['user_id' => $request->user()->id, 'medicine_id' => $data['medicine_id'], 'unit' => $data['unit']],
            ['quantity' => $data['quantity']],
        );

        return back()->with('status', __('Saved to your short list.'));
    }

    public function forget(Request $request, ShortListItem $item): RedirectResponse
    {
        $this->buyer($request);
        if ($item->user_id !== $request->user()->id) {
            throw new AuthorizationException;
        }
        $item->delete();

        return back();
    }

    /** Order straight from the short list: the item moves into the cart at the shop's own price. */
    public function orderFromShortList(Request $request, ShortListItem $item): RedirectResponse
    {
        $this->buyer($request);
        if ($item->user_id !== $request->user()->id) {
            throw new AuthorizationException;
        }

        return $this->attempt(function () use ($request, $item) {
            $this->invoices->addLine($this->cart($request, true), $item->medicine, $item->unit, (float) $item->quantity, $request->user());
            $item->delete();
        });
    }

    public function place(Request $request): RedirectResponse
    {
        $this->buyer($request);
        $ways = $this->terms->payments($request->user());
        $data = $request->validate([
            'delivery_mode' => ['required', 'in:collect,send,delivery'], 'note' => ['nullable', 'string', 'max:300'],
            'voice' => OrderNotes::RULES['voice'], 'photo' => OrderNotes::RULES['photo'], 'wanted_time' => OrderNotes::RULES['wanted_time'],
            'payment_terms' => [$ways ? 'required' : 'nullable', 'in:'.implode(',', $ways ?: ['cod'])],
        ]);
        $cart = $this->cart($request, false);
        abort_unless($cart, 404);

        // The minimum order quantity (spec ch 14): a clear message naming each line that is short.
        $short = $this->terms->shortLines($cart, $request->user());
        if ($short) {
            return back()->withErrors(['order' => collect($short)->map(fn ($s) => __('The minimum for {name} is {n}.', ['name' => $s['name'], 'n' => $s['minimum'] + 0]))->implode(' ')]);
        }

        $cart->update(['delivery_mode' => $data['delivery_mode'], 'note' => $data['note'] ?? null, 'payment_terms' => $data['payment_terms'] ?? null]);

        try {
            $order = $this->invoices->place($cart);
            app(OrderNotes::class)->attach($order, ['voice' => $request->file('voice'), 'photo' => $request->file('photo'), 'wanted_time' => $data['wanted_time'] ?? null]);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        $message = match ($this->notice->at()) {
            'holiday' => 'Order sent. We are closed today and will check it on the next working day.',
            'night' => 'Order sent. It is night now, so we will check it in the morning.',
            default => 'Order sent. We are checking it now.',
        };

        return redirect()->route($this->kind($request).'.orders.show', $order)->with('status', __($message));
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $this->owned($request, $invoice);

        return Inertia::render('paikari/order', ['portal' => $this->portal($request), 'order' => $this->detail($invoice->load('lines.medicine.company'))]);
    }

    /** More for an order already sent: a note, a voice note, a photo, the time it is needed by (spec ch 23). */
    public function addNote(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->owned($request, $invoice);
        $data = $request->validate(OrderNotes::RULES);

        return $this->attempt(fn () => app(OrderNotes::class)->addLater($invoice, ['note' => $data['note'] ?? null, 'wanted_time' => $data['wanted_time'] ?? null, 'voice' => $request->file('voice'), 'photo' => $request->file('photo')]));
    }

    public function addMore(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->owned($request, $invoice);
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'unit' => ['required', 'in:piece,strip,box'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        return $this->attempt(fn () => $this->invoices->addMore($invoice, Medicine::findOrFail($data['medicine_id']), $data['unit'], (float) $data['quantity']));
    }

    /** A prescription goes to the pharmacy only: a private file, seen by staff and admin and nobody else. */
    public function readNotice(Request $request, Notice $notice): RedirectResponse
    {
        $this->buyer($request);
        if ($notice->user_id !== $request->user()->id) {
            throw new AuthorizationException;
        }
        $notice->update(['read_at' => now()]);

        return back();
    }

    public function prescription(Request $request): RedirectResponse
    {
        $this->buyer($request);
        if ($request->user()->account_type !== AccountType::Paikari) {
            throw new AuthorizationException;
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $file = $data['file'];

        Prescription::create([
            'user_id' => $request->user()->id, 'path' => app(ImageCompressor::class)->store($file, 'prescriptions'), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 150), 'note' => $data['note'] ?? null,
        ]);

        return back()->with('status', __('Prescription sent to the pharmacy.'));
    }

    /** The return section: everything this shop bought, with "Returnable" on what is inside the window. Older items have no option at all. */
    public function returns(Request $request): Response
    {
        $this->buyer($request);
        $from = $request->query('from');
        $to = $request->query('to');

        return Inertia::render('paikari/returns', [
            'portal' => $this->portal($request),
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
        $this->buyer($request);
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

        return back()->with('status', __('Return request sent. Bring the goods to the shop so we can check them.'));
    }

    public function received(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->owned($request, $invoice);

        try {
            app(Dispatching::class)->received($invoice);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Thank you. Glad it arrived.'));
    }

    public function notReceived(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->owned($request, $invoice);

        try {
            app(Dispatching::class)->notReceived($invoice);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('We are sorry. We will look into it and call you.'));
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->owned($request, $invoice);

        try {
            // Before staff start picking the shop cancels by itself; after that it can only ask.
            if (! $this->invoices->pickingStarted($invoice)) {
                $this->invoices->cancel($invoice, $request->user());

                return redirect()->route($this->kind($request).'.index')->with('status', __('Order cancelled.'));
            }

            $this->invoices->requestCancel($invoice);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('We have your cancellation request. Our staff will confirm it.'));
    }

    /** What this shop keeps ordering: the same medicine and unit on two or more checked orders (spec 4.4.7). */
    private function usual(Request $request): array
    {
        return DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->join('medicines', 'medicines.id', '=', 'invoice_lines.medicine_id')
            ->where('invoices.customer_id', $request->user()->id)->whereIn('invoices.status', ['verified', 'final'])
            ->whereNull('invoice_lines.removed_at')->where('invoice_lines.quantity', '>', 0)
            ->groupBy('medicines.id', 'medicines.brand_name', 'medicines.strength', 'invoice_lines.unit')
            ->havingRaw('count(distinct invoices.id) >= 2')
            ->orderByDesc(DB::raw('count(distinct invoices.id)'))->limit(8)
            ->get(['medicines.id', 'medicines.brand_name as name', 'medicines.strength', 'invoice_lines.unit', DB::raw('round(avg(invoice_lines.quantity), 0) as quantity')])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'strength' => $r->strength, 'unit' => $r->unit, 'quantity' => max(1, (float) $r->quantity)])
            ->all();
    }

    private function probe(Request $request): Invoice
    {
        return (new Invoice(['kind' => $this->kind($request), 'channel' => 'online']))->setRelation('customer', $request->user());
    }

    private function buyer(Request $request): void
    {
        if (! in_array($request->user()?->account_type, [AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }
    }

    /** Which portal this buyer is in: the route prefix, route-name prefix and invoice kind. */
    private function kind(Request $request): string
    {
        return $request->user()->account_type === AccountType::Wholesaler ? 'wholesale' : 'paikari';
    }

    /** Only a buyer who has the medicine category can put medicine in a cart. */
    private function category(Request $request): void
    {
        if (! $request->user()->hasCategory('medicine')) {
            throw new AuthorizationException;
        }
    }

    /** What the pages need to know about where they are: the URL base, and a bulk buyer's terms. */
    private function portal(Request $request): array
    {
        $user = $request->user();
        $bulk = $user->account_type === AccountType::Wholesaler;

        return [
            'base' => '/'.$this->kind($request),
            'bulk' => $bulk,
            'moq' => $this->terms->moq($user),
            'payments' => $this->terms->payments($user),
        ];
    }

    private function owned(Request $request, Invoice $invoice): void
    {
        $this->buyer($request);
        if ($invoice->customer_id !== $request->user()->id || ($invoice->channel !== 'online' && $invoice->status !== 'final')) {
            throw new AuthorizationException;
        }
    }

    private function cart(Request $request, bool $create): ?Invoice
    {
        $cart = Invoice::where('customer_id', $request->user()->id)->where('channel', 'online')->where('status', 'draft')->first();

        return $cart ?? ($create ? $this->invoices->open($this->kind($request), $request->user(), $request->user(), 'online') : null);
    }

    private function attempt(callable $do): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back();
    }

    /** What the buyer may see of an order. Nothing about stock or cost is ever put here. */
    private function detail(Invoice $i): array
    {
        $totals = $this->invoices->totals($i);

        return [
            'id' => $i->id, 'status' => $i->status, 'number' => $i->number, 'cancel_requested' => $i->cancel_requested_at !== null, 'delivery_mode' => $i->delivery_mode, 'note' => $i->note,
            'wanted_time' => $i->wanted_time, 'has_voice' => $i->voice_path !== null, 'has_photo' => $i->photo_path !== null, 'can_note' => app(OrderNotes::class)->open($i),
            'paid_amount' => (float) $i->paid_amount, 'total' => $totals['total'], 'subtotal' => $totals['subtotal'], 'delivery' => $totals['delivery'],
            'sent_at' => $i->sent_at?->toIso8601String(), 'tracking' => $i->tracking, 'delivery_extra' => (float) $i->delivery_extra, 'courier_terms' => $i->courier_terms, 'cartons' => $i->cartons, 'delivered' => $i->delivered_at !== null, 'not_received' => $i->not_received_at !== null,
            'lines' => $i->lines->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'company' => $l->medicine->company?->name,
                'offer' => $l->offer_product_id !== null, 'unit' => $l->unit, 'quantity' => (float) $l->quantity, 'requested' => (float) ($l->requested_quantity ?? $l->quantity),
                'check' => $l->verify_status, 'unit_price' => (float) $l->unit_price, 'total' => $l->lineTotal(),
            ])->values(),
        ];
    }
}
