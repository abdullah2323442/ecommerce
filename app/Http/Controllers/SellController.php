<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\MarketCase;
use App\Models\MarketOrder;
use App\Models\MarketStatement;
use App\Services\Market\MarketCases;
use App\Services\Market\MarketOrders;
use App\Services\Market\Marketplace;
use App\Services\Market\Penalties;
use App\Services\Market\SellerReports;
use App\Services\Market\SellerTrust;
use App\Services\Market\Statements;
use App\Models\ListingQuestion;
use App\Models\PriceOffer;
use App\Services\Market\PriceOffers;
use App\Services\Market\ListingQuestions;
use App\Services\Market\ListingVariants;
use App\Services\Market\Catalog;
use App\Services\Market\ListingPhotos;
use App\Models\ListingPhoto;
use App\Models\CatalogProduct;
use App\Services\Wallet\Tokens;
use Illuminate\Support\Carbon;
use App\Services\Stock\PriceWarningException;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A trade account sells here (spec ch 14): its own listings, which wait for us, and the little it decides itself. */
class SellController extends Controller
{
    public function __construct(private Marketplace $market, private MarketOrders $orders, private Statements $statements, private MarketCases $cases, private SellerReports $reports, private SellerTrust $trust, private Penalties $penalties, private Tokens $tokens) {}

    public function index(Request $request): Response
    {
        $seller = $this->seller($request);

        return Inertia::render('market/sell', [
            'listings' => Listing::where('seller_id', $seller->id)->with('sector:id,name,unit', 'extraSectors:id')->latest('id')->get()->map(fn (Listing $l) => $this->row($l)),
            'sectors' => $this->market->sectorsFor($seller)->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'unit' => $s->unit, 'allow_fraction' => $s->allow_fraction, 'fields' => $s->fieldList(), 'catalog_required' => $s->catalog_required,
                'catalog' => app(Catalog::class)->choices($s)->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            ]),
            'asked' => CatalogProduct::where('requested_by', $seller->id)->latest('id')->limit(10)->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'note' => $p->note]),
            'paused' => (bool) $seller->seller_paused,
            'shop' => ['address' => $seller->shop_address, 'name_shown' => (bool) $seller->show_shop_name],
            'trust' => $this->trust->status($seller),
            'token' => ($t = $this->tokens->sellerToken($seller)) ? ['amount' => (float) $t->amount, 'min_order' => (float) $t->min_order, 'per_buyer' => $t->per_buyer, 'active' => $t->active, 'ends_at' => $t->ends_at?->toDateString()] : null,
        ]);
    }

    /** The orders for this seller's listings. Opening the list marks them seen, which is what the unseen count on the menu counts. */
    public function orders(Request $request): Response
    {
        $seller = $this->seller($request);
        $rows = MarketOrder::where('seller_id', $seller->id)->with('cases')->latest('id')->limit(100)->get();
        $page = Inertia::render('market/seller-orders', [
            'orders' => $rows->map(fn (MarketOrder $o) => $this->orders->forSeller($o)),
            'paused' => (bool) $seller->seller_paused,
            'record' => $this->cases->record($seller),
        ]);
        $this->orders->markSeen($seller);

        return $page;
    }

    /** What the seller is shown about their own business: what is looked at, what earns, which month rose, and how they rank (spec ch 14). */
    public function reports(Request $request): Response
    {
        $seller = $this->seller($request);
        $rank = $this->reports->rankOf($seller);

        // One person can run more than one business (wood and clothing): each is shown on its own, never mixed (spec ch 3). No choice means all together.
        $businesses = Sector::whereIn('id', Listing::where('seller_id', $seller->id)->select('sector_id'))->orderBy('name')->get(['id', 'name', 'slug']);
        $picked = $businesses->firstWhere('slug', (string) $request->query('business'));

        return Inertia::render('market/reports', [
            'businesses' => $businesses->map(fn (Sector $s) => ['slug' => $s->slug, 'name' => $s->name])->values(), 'business' => $picked?->slug,
            'products' => $this->reports->products($seller, 90, $picked?->id), 'months' => $this->reports->months($seller, 12, $picked?->id),
            'rank' => $rank + ['label' => SellerReports::label($rank['score'], $rank['proven'])], 'proven' => SellerReports::PROVEN,
        ]);
    }

    /** The month-end statements, with Paid and Problem to answer them (spec ch 14). Open to a seller whose selling is switched off, so they can answer. */
    public function statements(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->account_type === AccountType::Wholesaler, 403);

        return Inertia::render('market/statements', [
            'statements' => MarketStatement::where('seller_id', $user->id)->latest('month')->get()->map(fn (MarketStatement $s) => [
                'id' => $s->id, 'month' => $s->month, 'orders' => $s->orders, 'sales' => (float) $s->sales, 'commission' => (float) $s->commission, 'status' => $s->status,
                'seller_note' => $s->seller_note, 'our_note' => $s->our_note, 'sent_at' => $s->sent_at->toIso8601String(),
            ]),
            'stopped' => $user->statementStopped(), 'replyDays' => $this->statements->replyDays(),
            // A penalty stays until we lift it; the seller is told why, in our words.
            'penalties' => $this->penalties->active($user)->map(fn ($p) => ['step' => $p->step, 'reason' => $p->reason, 'at' => $p->created_at->toIso8601String()])->values(),
        ]);
    }

    public function reply(Request $request, MarketStatement $statement): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->account_type === AccountType::Wholesaler, 403);
        $data = $request->validate(['answer' => ['required', 'in:paid,problem'], 'note' => ['nullable', 'string', 'max:300']]);

        try {
            $this->statements->reply($statement, $user, $data['answer'], $data['note'] ?? null);
        } catch (StockException $e) {
            return back()->withErrors(['statement' => __($e->getMessage())]);
        }

        return back()->with('status', __('Thank you. We will look at it.'));
    }

    /** Solve a buyer's complaint, take the goods back, or say it cannot be solved (which brings it to us). */
    public function answerCase(Request $request, MarketCase $case): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:solved,return,cannot'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->handle($request, fn ($seller) => $this->cases->answer($case->load('order'), $seller, $data['action'], $data['note'] ?? null), 'Thank you. The buyer has been told.');
    }

    public function accept(Request $request, MarketOrder $order): RedirectResponse
    {
        return $this->handle($request, fn ($seller) => $this->orders->accept($order, $seller), 'Accepted.');
    }

    public function ship(Request $request, MarketOrder $order): RedirectResponse
    {
        $data = $request->validate(['tracking' => ['nullable', 'string', 'max:120']]);

        return $this->handle($request, fn ($seller) => $this->orders->ship($order, $seller, $data['tracking'] ?? null), 'Marked as sent.');
    }

    public function cancelOrder(Request $request, MarketOrder $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        return $this->handle($request, function ($seller) use ($order, $data) {
            abort_unless($order->seller_id === $seller->id, 404);

            return $this->orders->cancel($order, 'seller', $data['reason']);
        }, 'Order cancelled.');
    }

    private function handle(Request $request, callable $do, string $done): RedirectResponse
    {
        $seller = $this->seller($request);

        try {
            $do($seller);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    public function store(Request $request): RedirectResponse
    {
        $seller = $this->seller($request);
        $data = $this->validated($request);

        try {
            $this->market->submit($seller, $data, $request->file('photo'));
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('Sent. We read every listing and set our commission before it is shown.'));
    }

    public function update(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);
        $data = $this->validated($request, partial: true);

        try {
            $this->market->update($listing, $data, $request->file('photo'));
        } catch (PriceWarningException $e) {
            // Not refused: the seller is asked, and tries again with "I am sure".
            return back()->withErrors(['price_warning' => $e->getMessage()]);
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function pause(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);
        $data = $request->validate(['paused' => ['required', 'boolean']]);

        try {
            $this->market->pause($listing, (bool) $data['paused']);
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', $data['paused'] ? __('Paused.') : __('Shown again.'));
    }

    public function destroy(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);

        try {
            $this->market->remove($listing);
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('Removed.'));
    }

    /** Vacation mode (spec ch 14): no new orders while away; the ones already made carry on. */
    public function vacation(Request $request): RedirectResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $seller->forceFill(['seller_paused' => (bool) $data['paused']])->save();

        return back()->with('status', $data['paused'] ? __('You are on holiday. New orders are off.') : __('Welcome back. New orders are on.'));
    }

    /** The colour-and-size grid of one product (spec ch 15). Stock and price changes are the seller's own business and show at once. */
    public function variants(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);
        $data = $request->validate(['cells' => ['present', 'array', 'max:200'], 'cells.*.color' => ['nullable', 'string', 'max:40'], 'cells.*.size' => ['nullable', 'string', 'max:40'], 'cells.*.stock' => ['nullable', 'numeric'], 'cells.*.price' => ['nullable', 'numeric']]);

        try {
            app(ListingVariants::class)->sync($listing, $data['cells']);
        } catch (StockException $e) {
            return back()->withErrors(['variants' => __($e->getMessage())]);
        }

        return back()->with('status', __('Grid saved.'));
    }

    public function colorPhoto(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);
        $data = $request->validate(['color' => ['required', 'string', 'max:40'], 'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        try {
            app(ListingVariants::class)->setColorPhoto($listing, $data['color'], $request->file('photo'));
        } catch (StockException $e) {
            return back()->withErrors(['variants' => __($e->getMessage())]);
        }

        return back()->with('status', __('Photo saved.'));
    }

    /** Where buyers may collect (spec ch 20). Only useful once the owner has switched the seller's name on. */
    public function shopAddress(Request $request): RedirectResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate(['address' => ['nullable', 'string', 'max:250']]);
        $seller->forceFill(['shop_address' => filled($data['address'] ?? null) ? trim($data['address']) : null])->save();

        return back()->with('status', __('Saved.'));
    }

    /** The buyer came and took it. */
    public function collected(Request $request, MarketOrder $order): RedirectResponse
    {
        return $this->handle($request, fn ($seller) => $this->orders->collected($order, $seller), 'Done. The sale is complete.');
    }

    /** More pictures of a product (spec ch 13). They show at once; the seller's own pictures need no new review. */
    public function addPhoto(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        try {
            app(ListingPhotos::class)->add($listing, $request->file('photo'));
        } catch (StockException $e) {
            return back()->withErrors(['photo' => __($e->getMessage())]);
        }

        return back()->with('status', __('Photo saved.'));
    }

    /** The file a buyer downloads after paying (spec ch 13), with how many times and for how many days. */
    public function attachDigital(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);
        $data = $request->validate(['file' => ['required', 'file'], 'max' => ['required', 'integer', 'min:1', 'max:20'], 'days' => ['required', 'integer', 'min:1', 'max:365']]);

        try {
            app(\App\Services\Market\DigitalGoods::class)->attach($listing, $request->file('file'), (int) $data['max'], (int) $data['days']);
        } catch (StockException $e) {
            return back()->withErrors(['digital' => __($e->getMessage())]);
        }

        return back()->with('status', __('The file is saved. Buyers pay online first, then download it.'));
    }

    public function removeDigital(Request $request, Listing $listing): RedirectResponse
    {
        $this->mine($request, $listing);
        app(\App\Services\Market\DigitalGoods::class)->remove($listing);

        return back()->with('status', __('The file is removed.'));
    }

    public function removePhoto(Request $request, ListingPhoto $photo): RedirectResponse
    {
        $this->mine($request, $photo->listing);
        app(ListingPhotos::class)->remove($photo);

        return back()->with('status', __('Removed.'));
    }

    public function mainPhoto(Request $request, ListingPhoto $photo): RedirectResponse
    {
        $this->mine($request, $photo->listing);
        app(ListingPhotos::class)->makeMain($photo);

        return back()->with('status', __('Photo saved.'));
    }

    /** A product that is not in the catalog: ask us to add it (spec ch 13). */
    public function catalogRequest(Request $request): RedirectResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate(['sector_id' => ['required', 'integer'], 'name' => ['required', 'string', 'min:2', 'max:150']]);
        $sector = $this->market->sectorsFor($seller)->firstWhere('id', (int) $data['sector_id']);
        abort_unless($sector !== null, 404);

        try {
            app(Catalog::class)->request($seller, $sector, $data['name']);
        } catch (StockException $e) {
            return back()->withErrors(['catalog' => __($e->getMessage())]);
        }

        return back()->with('status', __('Sent. We will add it to the catalog and tell you.'));
    }

    /** Prices buyers offered, and why they leave without buying (spec ch 13). */
    public function offers(Request $request): Response
    {
        $seller = $this->seller($request);
        $offers = app(PriceOffers::class);

        return Inertia::render('market/seller-offers', ['offers' => $offers->forSeller($seller), 'reasons' => $offers->reasons($seller), 'hours' => $offers->hours()]);
    }

    public function answerOffer(Request $request, PriceOffer $offer): RedirectResponse
    {
        $seller = $this->seller($request);
        abort_unless($offer->seller_id === $seller->id, 404);
        $data = $request->validate(['answer' => ['required', 'in:accept,counter,decline'], 'price' => ['required_if:answer,counter', 'nullable', 'numeric', 'gt:0', 'max:100000000']]);
        $offers = app(PriceOffers::class);

        try {
            match ($data['answer']) {
                'accept' => $offers->accept($offer->load('listing', 'buyer'), $seller),
                'counter' => $offers->counter($offer->load('listing', 'buyer'), $seller, (float) $data['price']),
                'decline' => $offers->decline($offer->load('listing', 'buyer'), $seller),
            };
        } catch (StockException $e) {
            return back()->withErrors(['offer' => __($e->getMessage())]);
        }

        return back()->with('status', __('Sent to the buyer.'));
    }

    /** Questions under this seller's products (spec ch 23). */
    public function questions(Request $request): Response
    {
        $seller = $this->seller($request);
        $row = fn (ListingQuestion $q) => ['id' => $q->id, 'listing' => $q->listing->title, 'question' => $q->question, 'answer' => $q->answer, 'at' => $q->created_at->toIso8601String(), 'late' => $q->escalated_at !== null];
        $mine = ListingQuestion::where('seller_id', $seller->id)->where('hidden', false)->with('listing:id,title');

        return Inertia::render('market/questions', [
            'waiting' => (clone $mine)->whereNull('answered_at')->oldest('id')->get()->map($row),
            'answered' => (clone $mine)->whereNotNull('answered_at')->latest('answered_at')->limit(30)->get()->map($row),
            'hours' => app(ListingQuestions::class)->answerHours(),
        ]);
    }

    public function answerQuestion(Request $request, ListingQuestion $question): RedirectResponse
    {
        $seller = $this->seller($request);
        abort_unless($question->seller_id === $seller->id, 404);
        $data = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:1500']]);

        app(ListingQuestions::class)->answer($question->load('listing', 'asker'), $seller, $data['answer']);

        return back()->with('status', __('Answer saved.'));
    }

    /** The seller's own token (spec ch 24): one for all buyers, only on their products, paid for by them. */
    public function token(Request $request): RedirectResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'], 'min_order' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'per_buyer' => ['required', 'integer', 'min:1', 'max:50'], 'active' => ['required', 'boolean'], 'ends_at' => ['nullable', 'date', 'after:today'],
        ]);

        $this->tokens->saveSellerToken($seller, (float) $data['amount'], (float) ($data['min_order'] ?? 0), (int) $data['per_buyer'], (bool) $data['active'], isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null);

        return back()->with('status', __('Your token is saved.'));
    }

    private function seller(Request $request)
    {
        $user = $request->user();
        if (! $this->market->canSell($user)) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function mine(Request $request, Listing $listing): void
    {
        $this->seller($request);
        abort_unless($listing->seller_id === $request->user()->id, 404);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'sector_id' => [$req, 'integer'],
            'title' => [$req, 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'public_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'min_qty' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'stock' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'commission_request' => ['nullable', 'string', 'max:300'],
            'item_height' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'item_length' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'item_width' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'item_size_unit' => ['nullable', 'in:inch,foot,cm'], 'pickup' => ['nullable', 'boolean'], 'cod_allowed' => ['nullable', 'boolean'], 'confirm_price' => ['nullable', 'boolean'],
            'item_weight' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'expires_on' => ['nullable', 'date'], 'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'alt_names' => ['nullable', 'string', 'max:200'], 'preorder_days' => ['nullable', 'integer', 'in:2,3,4,5'], 'return_days' => ['nullable', 'integer', 'min:1', 'max:30'], 'return_note' => ['nullable', 'string', 'max:200'],
            'seo_title' => ['nullable', 'string', 'max:70'], 'seo_description' => ['nullable', 'string', 'max:160'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'low_stock_at' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'extra_sector_ids' => ['nullable', 'string', 'max:100', 'regex:/^[0-9,]*$/'],
            'catalog_product_id' => ['nullable', 'integer'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }

    private function row(Listing $l): array
    {
        return [
            'id' => $l->id, 'title' => $l->title, 'description' => $l->description, 'status' => $l->status, 'sector' => $l->sector->name, 'sector_id' => $l->sector_id, 'unit' => $l->sector->unit,
            'public_price' => $l->public_price !== null ? (float) $l->public_price : null, 'wholesale_price' => $l->wholesale_price !== null ? (float) $l->wholesale_price : null,
            'min_qty' => (float) $l->min_qty, 'delivery_charge' => (float) $l->delivery_charge, 'stock' => $l->stock !== null ? (float) $l->stock : null,
            'item_weight' => $l->item_weight !== null ? (float) $l->item_weight : null, 'expires_on' => $l->expires_on?->toDateString(), 'warranty_months' => $l->warranty_months, 'alt_names' => $l->alt_names, 'preorder_days' => $l->preorder_days, 'return_days' => $l->return_days, 'return_note' => $l->return_note, 'seo_title' => $l->seo_title, 'seo_description' => $l->seo_description,
            'pickup' => (bool) $l->pickup, 'cod_allowed' => (bool) $l->cod_allowed, 'digital' => $l->digital_path ? ['name' => $l->digital_name, 'max' => (int) $l->digital_max, 'days' => (int) $l->digital_days] : null, 'catalog_product_id' => $l->catalog_product_id, 'photos' => app(ListingPhotos::class)->forSeller($l),
            'cost_price' => $l->cost_price !== null ? (float) $l->cost_price : null, 'low_stock_at' => $l->low_stock_at !== null ? (float) $l->low_stock_at : null,
            'extra_sector_ids' => $l->extraSectors->pluck('id')->all(),
            'variants' => app(ListingVariants::class)->forSeller($l), 'size' => ['height' => $l->item_height !== null ? (float) $l->item_height : null, 'length' => $l->item_length !== null ? (float) $l->item_length : null, 'width' => $l->item_width !== null ? (float) $l->item_width : null, 'unit' => $l->item_size_unit],
            'review_note' => $l->review_note, 'commission_request' => $l->commission_request, 'has_photo' => $l->photo_path !== null,
            // The seller knows the commission we set; it is added to the price buyers pay.
            'commission' => $l->commission_kind ? ['kind' => $l->commission_kind, 'value' => (float) $l->commission_value] : null,
        ];
    }
}
