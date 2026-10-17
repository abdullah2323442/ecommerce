<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\ListingVariant;
use App\Services\Market\ListingVariants;
use App\Services\Market\Shops;
use App\Services\Market\Reviews;
use App\Services\Market\ListingPhotos;
use App\Models\ListingPhoto;
use App\Models\ListingReviewPhoto;
use App\Services\Market\Catalog;
use App\Models\User;
use App\Services\Stock\StockException;
use App\Services\Wallet\Tokens;
use Illuminate\Http\RedirectResponse;
use App\Models\Sector;
use App\Services\Analytics\Behavior;
use App\Services\Market\ListingReports;
use App\Services\Market\ListingQuestions;
use App\Services\Market\Marketplace;
use App\Services\Market\SellerReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The market beyond medicine (spec ch 13): sectors as big icons, a budget that brings the right range forward, a Premium stall that fills
 * itself, nearby sellers first. Every price shown is the one for this viewer, with our commission already in it.
 */
class MarketController extends Controller
{
    private const PER_PAGE = 24;

    public function __construct(private Marketplace $market, private Behavior $behavior, private SellerReports $reports, private ListingReports $listingReports) {}

    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $sector = $request->query('sector');
        $premium = $request->boolean('premium');
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from') !== null ? (float) $request->query('from') : null;
        $to = $request->query('to') !== null && $request->query('to') !== '' ? (float) $request->query('to') : null;
        $minStars = in_array((int) $request->query('stars'), [3, 4], true) ? (int) $request->query('stars') : null;
        $sort = in_array($request->query('sort'), ['new', 'low', 'high', 'popular'], true) ? $request->query('sort') : 'near';

        $all = $this->market->visible($viewer, is_string($sector) ? $sector : null);
        $sectors = Sector::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Sector $s) => ['slug' => $s->slug, 'name' => app()->getLocale() === 'bn' && $s->name_bn ? $s->name_bn : $s->name, 'count' => $this->market->visible($viewer, $s->slug)->count()])
            ->filter(fn ($s) => $s['count'] > 0)->values();

        // The budget ranges that really have something in them, for what is open now (before the budget itself is applied).
        $ranges = $this->market->ranges($all->pluck('price'));

        $rows = $all
            ->when($premium, fn ($c) => $c->filter(fn ($r) => $r['premium']))
            ->when(! $premium && $request->query('stall') === 'normal', fn ($c) => $c->filter(fn ($r) => ! $r['premium']))
            ->when($from !== null, fn ($c) => $c->filter(fn ($r) => $r['price'] >= $from && ($to === null || $r['price'] < $to)))
            ->when($q !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['listing']->title.' '.$r['listing']->alt_names.' '.$r['listing']->description), mb_strtolower($q))));

        // Rating: only products whose shown reviews average at least this many stars (spec ch 13 filters).
        if ($minStars !== null) {
            $avg = app(Reviews::class)->averages($rows->pluck('listing.id')->all());
            $rows = $rows->filter(fn ($r) => ($avg[$r['listing']->id]['average'] ?? 0) >= $minStars);
        }

        // Popularity: how many orders it has had that were not cancelled.
        $sold = $sort === 'popular'
            ? \App\Models\MarketOrder::whereIn('listing_id', $rows->pluck('listing.id')->all())->where('status', '!=', 'cancelled')->selectRaw('listing_id, count(*) as n')->groupBy('listing_id')->pluck('n', 'listing_id')
            : collect();

        $ranks = $sort === 'near' ? $this->reports->ranks() : [];

        $rows = match ($sort) {
            'low' => $rows->sortBy('price'),
            'high' => $rows->sortByDesc('price'),
            'popular' => $rows->sortBy([[fn ($a, $b) => ($sold[$b['listing']->id] ?? 0) <=> ($sold[$a['listing']->id] ?? 0)], [fn ($a, $b) => $b['listing']->approved_at <=> $a['listing']->approved_at]]),
            'new' => $rows->sortByDesc(fn ($r) => $r['listing']->approved_at),
            // Nearby first; then the sellers who ship fast and have few returns (spec ch 14: better-ranked products go higher); then the newest.
            default => $rows->sortBy([['near', 'desc'], [fn ($a, $b) => ($ranks[$b['listing']->seller_id]['score'] ?? SellerReports::UNPROVEN_SCORE) <=> ($ranks[$a['listing']->seller_id]['score'] ?? SellerReports::UNPROVEN_SCORE)], [fn ($a, $b) => $b['listing']->approved_at <=> $a['listing']->approved_at]]),
        };

        // One product, many sellers: one card for the best of them (spec ch 13); the page shows them all.
        $sellers = $rows->filter(fn ($r) => $r['listing']->catalog_product_id !== null)->groupBy(fn ($r) => $r['listing']->catalog_product_id)->map->count();
        $rows = $rows->unique(fn ($r) => $r['listing']->catalog_product_id !== null ? 'c'.$r['listing']->catalog_product_id : 'l'.$r['listing']->id)->values();

        $page = max(1, (int) $request->query('page', 1));
        $total = $rows->count();

        // One banner for the kind of business being looked at: its own picture and line.
        $chosen = is_string($sector) ? Sector::where('slug', $sector)->where('is_active', true)->first() : null;
        $hero = $chosen ? [
            'slug' => $chosen->slug, 'name' => $chosen->name, 'name_bn' => $chosen->name_bn, 'tagline' => $chosen->tagline, 'tagline_bn' => $chosen->tagline_bn,
            'image' => $chosen->hero_path ? route('market.hero', ['slug' => $chosen->slug, 'v' => substr(md5($chosen->hero_path), 0, 8)]) : null,
        ] : null;

        return Inertia::render('market/index', [
            'hero' => $hero,
            'sectors' => $sectors,
            'ranges' => $ranges,
            'hasPremium' => $all->contains(fn ($r) => $r['premium']),
            'items' => $this->withStars($rows->forPage($page, self::PER_PAGE)->map(fn ($r) => $this->card($r) + ['sellers' => (int) ($sellers[$r['listing']->catalog_product_id] ?? 1)])->values()),
            'total' => $total, 'page' => $page, 'perPage' => self::PER_PAGE,
            'filters' => ['sector' => is_string($sector) ? $sector : null, 'premium' => $premium, 'q' => $q, 'from' => $from, 'to' => $to, 'sort' => $sort, 'stars' => $minStars],
        ]);
    }

    public function show(Request $request, Listing $listing): Response
    {
        $viewer = $request->user();
        $listing->load(['sector', 'seller']);
        $p = $this->market->priceFor($listing, $viewer);

        // Not for this viewer, or not there: quietly home, never a hint that something is hidden (spec ch 05).
        abort_if($p === null, 404);

        // Looked at, whether or not it is bought (the seller sees this, not who looked). A seller looking at their own listing is not interest.
        if ($viewer?->id !== $listing->seller_id) {
            $this->behavior->record('listing_view', $listing->id);
        }

        // A token that would come off the first order (spec ch 24): the larger of ours and this seller's own.
        $pick = $viewer && $viewer->id !== $listing->seller_id
            ? app(Tokens::class)->forMarket($viewer, $listing->seller, round($p['min_qty'] * $p['price'], 2), round($p['min_qty'] * ($p['price'] - $p['seller_price']), 2))
            : null;

        return Inertia::render('market/show', [
            'token' => $pick ? $pick['amount'] : null,
            'questions' => app(ListingQuestions::class)->shown($listing),
            'gallery' => app(ListingPhotos::class)->gallery($listing),
            'cod' => (bool) $listing->cod_allowed,
            'digital' => $listing->digital_path !== null,
            'pickup' => $this->market->canPickup($listing) ? ['where' => $listing->seller->shop_address] : null,
            'reviews' => app(Reviews::class)->shown($listing),
            'rating' => app(Reviews::class)->summary($listing),
            'offers' => $this->offersFor($listing, $viewer),
            'can_ask' => $viewer !== null && $viewer->id !== $listing->seller_id,
            'wishlisted' => $viewer !== null && app(\App\Services\Market\Wishlist::class)->has($viewer, $listing),
            'districts' => config('bd.districts'),
            'listing' => $this->card(['listing' => $listing] + $p + ['premium' => $this->market->isPremium($listing, $p['price'])]) + [
                'description' => $listing->description, 'preorder_days' => $listing->preorder_days, 'return_days' => $listing->return_days ?? app(\App\Services\Market\MarketCases::class)->returnDays(), 'return_note' => $listing->return_note, 'seo_title' => $listing->seo_title, 'seo_description' => $listing->seo_description, 'delivery_charge' => (float) $listing->delivery_charge, 'min_qty' => $p['min_qty'],
                'in_stock' => $listing->variants()->exists() ? app(ListingVariants::class)->anyAvailable($listing) : ($listing->stock === null || (float) $listing->stock > 0),
                'grid' => app(ListingVariants::class)->forBuyer($listing, $viewer),
                'details' => ['weight' => $listing->item_weight !== null ? (float) $listing->item_weight : null, 'expires_on' => $listing->expires_on?->toDateString(), 'warranty_months' => $listing->warranty_months],
                'size' => $listing->item_height || $listing->item_length || $listing->item_width ? ['height' => $listing->item_height !== null ? (float) $listing->item_height : null, 'length' => $listing->item_length !== null ? (float) $listing->item_length : null, 'width' => $listing->item_width !== null ? (float) $listing->item_width : null, 'unit' => $listing->item_size_unit] : null,
                'allow_fraction' => (bool) $listing->sector->allow_fraction, 'return_rule' => $listing->sector->return_rule,
                'preorder' => (bool) $listing->sector->preorder,
                // Anyone signed in except the seller may say it is a copy; once is enough.
                'can_report' => $viewer !== null && $viewer->id !== $listing->seller_id && ! ListingReport::where('listing_id', $listing->id)->where('reporter_id', $viewer->id)->exists(),
            ],
        ]);
    }

    /** A signed-in buyer asks under a product (spec ch 23). The seller is told; everyone reads the answer. */
    public function ask(Request $request, Listing $listing): RedirectResponse
    {
        abort_if($this->market->priceFor($listing->load(['sector', 'seller']), $request->user()) === null, 404);
        $data = $request->validate(['question' => ['required', 'string', 'max:500']]);

        try {
            app(ListingQuestions::class)->ask($listing, $request->user(), $data['question']);
        } catch (StockException $e) {
            return back()->withErrors(['question' => __($e->getMessage())]);
        }

        return back()->with('status', __('Your question is sent. The answer will show here.'));
    }

    /** "This is copied from me" (spec ch 14): we look, and remove it or turn the report down. */
    public function report(Request $request, Listing $listing): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        abort_if($this->market->priceFor($listing->load(['sector', 'seller']), $request->user()) === null, 404);

        try {
            $this->listingReports->report($listing, $request->user(), $data['reason']);
        } catch (StockException $e) {
            return back()->withErrors(['report' => __($e->getMessage())]);
        }

        return back()->with('status', __('Thank you. We will look at it.'));
    }

    /** A kind of business's banner, shown to everyone. Only the file the owner saved for that sector. */
    public function hero(string $slug): StreamedResponse
    {
        $path = Sector::where('slug', $slug)->where('is_active', true)->value('hero_path');
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, null, ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The picture: the public sees it only while the listing is shown to them; its seller and our staff see it any time. */
    public function photo(Request $request, Listing $listing): StreamedResponse
    {
        $viewer = $request->user();
        $mine = $viewer && ($viewer->id === $listing->seller_id || $viewer->can('manage-customers'));
        abort_unless($listing->photo_path !== null && Storage::exists($listing->photo_path), 404);
        abort_unless($mine || $this->market->priceFor($listing->load(['sector', 'seller']), $viewer) !== null, 404);

        return Storage::response($listing->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** The same product from every seller, the best first (spec ch 13). Names show only for shops with their own page; the rest are just numbered. */
    private function offersFor(Listing $listing, ?User $viewer): array
    {
        if ($listing->catalog_product_id === null) {
            return [];
        }

        $offers = app(Catalog::class)->offers($listing->catalogProduct, $viewer);

        if ($offers->count() < 2) {
            return [];
        }

        return $offers->values()->map(function (array $o, int $i) use ($listing) {
            /** @var Listing $l */
            $l = $o['listing'];

            return [
                'id' => $l->id, 'name' => $l->seller && app(Shops::class)->hasPage($l->seller) ? $this->market->sellerName($l) : __('Seller').' '.($i + 1),
                'price' => $o['price'], 'delivery' => $o['delivery'], 'total' => round($o['price'] + $o['delivery'], 2), 'rating' => $o['rating'], 'best' => $i === 0, 'this' => $l->id === $listing->id,
            ];
        })->all();
    }

    /** A shop's own page (spec ch 13). Only a seller whose name is switched on has one; for the rest this is the same as nothing being there. */
    public function shop(Request $request, User $seller): Response
    {
        $shops = app(Shops::class);
        abort_unless($shops->hasPage($seller), 404);
        $viewer = $request->user();

        return Inertia::render('market/shop', [
            'shop' => $shops->summary($seller, $viewer),
            'items' => $shops->products($seller, $viewer)->map(fn ($r) => $this->card($r))->values(),
        ]);
    }

    public function follow(Request $request, User $seller): RedirectResponse
    {
        try {
            app(Shops::class)->follow($request->user(), $seller);
        } catch (StockException $e) {
            return back()->withErrors(['shop' => __($e->getMessage())]);
        }

        return back()->with('status', __('You follow this shop now. We will tell you about new products.'));
    }

    public function unfollow(Request $request, User $seller): RedirectResponse
    {
        app(Shops::class)->unfollow($request->user(), $seller);

        return back()->with('status', __('You do not follow this shop now.'));
    }

    /** The stars on each card of a page of the list. */
    private function withStars($items)
    {
        $stars = app(Reviews::class)->averages($items->pluck('id')->all());

        return $items->map(fn (array $i) => $i + ['stars' => $stars[$i['id']] ?? null])->values();
    }

    /** One of a product's extra pictures: shown while the product is shown to this viewer. */
    public function extraPhoto(Request $request, ListingPhoto $photo): StreamedResponse
    {
        $viewer = $request->user();
        $listing = $photo->listing()->with(['sector', 'seller'])->firstOrFail();
        $mine = $viewer && ($viewer->id === $listing->seller_id || $viewer->can('manage-customers'));
        abort_unless(Storage::exists($photo->path), 404);
        abort_unless($mine || $this->market->priceFor($listing, $viewer) !== null, 404);

        return Storage::response($photo->path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** A picture a buyer put in a review: shown while the review is shown, to its writer and to staff any time. */
    public function reviewPhoto(Request $request, ListingReviewPhoto $photo): StreamedResponse
    {
        $viewer = $request->user();
        $review = $photo->review()->with('listing.sector', 'listing.seller')->firstOrFail();
        $mine = $viewer && ($viewer->id === $review->buyer_id || $viewer->can('manage-customers'));
        abort_unless(Storage::exists($photo->path), 404);
        abort_unless($mine || (! $review->hidden && $this->market->priceFor($review->listing, $viewer) !== null), 404);

        return Storage::response($photo->path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** The photo of one colour: shown while the listing is shown to this viewer. */
    public function variantPhoto(Request $request, ListingVariant $variant): StreamedResponse
    {
        $viewer = $request->user();
        $listing = $variant->listing()->with(['sector', 'seller'])->firstOrFail();
        $mine = $viewer && ($viewer->id === $listing->seller_id || $viewer->can('manage-customers'));
        abort_unless($variant->photo_path !== null && Storage::exists($variant->photo_path), 404);
        abort_unless($mine || $this->market->priceFor($listing, $viewer) !== null, 404);

        return Storage::response($variant->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Asked once per seller for a request, not once per card. */
    private function trustedSeller(User $seller): bool
    {
        return app(\App\Services\Market\SellerTrust::class)->trusted($seller);
    }

    /** What the list and the page show of a listing. The seller's price and our commission are never in it. */
    private function card(array $r): array
    {
        /** @var Listing $l */
        $l = $r['listing'];

        return [
            'id' => $l->id, 'title' => $l->title, 'price' => $r['price'], 'premium' => $r['premium'] ?? false, 'near' => $r['near'] ?? false,
            'unit' => $l->sector->unit, 'sector' => $l->sector->slug, 'sector_name' => $l->sector->name,
            'photo' => $l->photo_path !== null ? route('market.photo', ['listing' => $l->id, 'v' => substr(md5($l->photo_path.$l->updated_at), 0, 8)]) : null, 'seller' => $this->market->sellerName($l),
            // A seller with a clean record (or whom we marked) wears the badge; it says nothing about who they are.
            'trusted' => $l->seller ? $this->trustedSeller($l->seller) : false,
            // Only a shop with its own page can be opened.
            'shop' => $l->seller && app(Shops::class)->hasPage($l->seller) ? $l->seller_id : null,
        ];
    }
}
