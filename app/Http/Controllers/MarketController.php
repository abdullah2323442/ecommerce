<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Models\ListingReport;
use App\Models\ListingReviewPhoto;
use App\Models\ListingVariant;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\StorefrontBlock;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Analytics\Behavior;
use App\Services\Market\Catalog;
use App\Services\Market\Deals;
use App\Services\Market\ListingPhotos;
use App\Services\Media\ImageCompressor;
use App\Services\Market\ListingVideo;
use App\Services\Market\ListingQuestions;
use App\Services\Market\ListingReports;
use App\Services\Market\ListingVariants;
use App\Services\Market\MarketCases;
use App\Services\Market\Marketplace;
use App\Services\Market\Recommendations;
use App\Services\Market\Reviews;
use App\Services\Market\SellerReports;
use App\Services\Market\SellerTrust;
use App\Services\Market\Shops;
use App\Services\Market\Wishlist;
use App\Services\Stock\StockException;
use App\Services\Wallet\Tokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        // Inside a shop, a category narrows to everything at or below it. The rows before narrowing are kept to count each node.
        $inShop = $all;
        $category = $this->categoryFor(is_string($sector) ? $sector : null, $request->query('category'));
        if ($category) {
            $ids = $category->selfAndDescendantIds();
            $all = $all->filter(fn ($r) => in_array($r['listing']->category_id, $ids, true))->values();
        }
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
            ? MarketOrder::whereIn('listing_id', $rows->pluck('listing.id')->all())->where('status', '!=', 'cancelled')->selectRaw('listing_id, count(*) as n')->groupBy('listing_id')->pluck('n', 'listing_id')
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
            'image' => $chosen->hero_path ? route('market.hero', ['slug' => $chosen->slug, 'w' => 1600, 'v' => substr(md5($chosen->hero_path), 0, 8)]) : null,
        ] : null;

        $plain = $category === null && $q === '' && $from === null && ! $premium && $minStars === null && $request->query('stall') === null && $request->query('all') === null && $page === 1;

        return Inertia::render('market/index', [
            'category' => $category ? $this->categoryNode($category, $inShop) : null,
            'tree' => is_string($sector) && $chosen ? $this->categoryTree($chosen, $inShop) : [],
            'storefront' => $plain ? ($chosen ? $this->storefront($chosen, $inShop, $ranges, $total, $hero) : ($sector === null ? $this->home($all, $ranges, $total, $sectors) : null)) : null,
            'hero' => $hero,
            'sectors' => $sectors,
            'ranges' => $ranges,
            'hasPremium' => $all->contains(fn ($r) => $r['premium']),
            // Only on the plain market page, and only for a signed-in buyer who has ordered or saved something.
            'picked' => $plain && $sector === null ? $this->withStars(app(Recommendations::class)->for($viewer, $all)->map(fn ($r) => $this->card($r))->values()) : [],
            'items' => $this->withStars($rows->forPage($page, self::PER_PAGE)->map(fn ($r) => $this->card($r) + ['sellers' => (int) ($sellers[$r['listing']->catalog_product_id] ?? 1)])->values()),
            'total' => $total, 'page' => $page, 'perPage' => self::PER_PAGE,
            'filters' => ['category' => $category?->slug, 'sector' => is_string($sector) ? $sector : null, 'premium' => $premium, 'q' => $q, 'from' => $from, 'to' => $to, 'sort' => $sort, 'stars' => $minStars, 'all' => $request->query('all') !== null],
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
            'video' => $listing->video_path !== null ? route('market.video', $listing) : null,
            'cod' => (bool) $listing->cod_allowed,
            'digital' => $listing->digital_path !== null,
            'pickup' => $this->market->canPickup($listing) ? ['where' => $listing->seller->shop_address] : null,
            'reviews' => app(Reviews::class)->shown($listing),
            'rating' => app(Reviews::class)->summary($listing),
            'offers' => $this->offersFor($listing, $viewer),
            'can_ask' => $viewer !== null && $viewer->id !== $listing->seller_id,
            'wishlisted' => $viewer !== null && app(Wishlist::class)->has($viewer, $listing),
            'bundles' => app(Deals::class)->offeredFor($listing, $viewer),
            'districts' => config('bd.districts'),
            'listing' => $this->card(['listing' => $listing] + $p + ['premium' => $this->market->isPremium($listing, $p['price'])]) + [
                'description' => $listing->description, 'preorder_days' => $listing->preorder_days, 'return_days' => $listing->return_days ?? app(MarketCases::class)->returnDays(), 'return_note' => $listing->return_note, 'seo_title' => $listing->seo_title, 'seo_description' => $listing->seo_description, 'delivery_charge' => (float) $listing->delivery_charge, 'min_qty' => $p['min_qty'],
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
    /** Widths a phone or a tile can ask for with ?w=, so nobody downloads a 2400 pixel picture to fill a 375 pixel screen (spec ch 28: fast on slow internet). */
    public const HERO_WIDTHS = [480, 960, 1600];

    public function hero(Request $request, string $slug): StreamedResponse
    {
        $path = Sector::where('slug', $slug)->where('is_active', true)->value('hero_path');
        abort_unless($path && Storage::exists($path), 404);

        $w = (int) $request->query('w');

        if (in_array($w, self::HERO_WIDTHS, true)) {
            // A smaller copy is made once, the first time it is asked for, and kept beside the original.
            $small = 'sectors/w'.$w.'/'.basename($path);

            if (! Storage::exists($small) && ($bytes = app(ImageCompressor::class)->compress((string) Storage::get($path), $w)) !== null) {
                Storage::put($small, $bytes);
            }

            $path = Storage::exists($small) ? $small : $path;
        }

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

    /** The product's video: seen by whoever may see the product at all, with ranges so a phone can skip about. */
    public function video(Request $request, Listing $listing): BinaryFileResponse
    {
        $viewer = $request->user();
        $listing->loadMissing(['sector', 'seller']);
        $mine = $viewer && ($viewer->id === $listing->seller_id || $viewer->can('manage-customers'));
        abort_unless($listing->video_path !== null && Storage::exists($listing->video_path), 404);
        abort_unless($mine || $this->market->priceFor($listing, $viewer) !== null, 404);

        return response()->file(Storage::path($listing->video_path), ['Cache-Control' => 'private, max-age=86400']);
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
        return app(SellerTrust::class)->trusted($seller);
    }

    /** The category named in the address, inside the shop being looked at (or the first of that name anywhere). */
    private function categoryFor(?string $sector, mixed $slug): ?Category
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Category::where('slug', $slug)->where('is_active', true)
            ->when($sector, fn ($q) => $q->whereHas('sector', fn ($s) => $s->where('slug', $sector)))->first();
    }

    /** How many of the visible products sit at or below each category of one shop. @return array<int, int> */
    private function countsBelow(Sector $sector, $rows): array
    {
        $own = $rows->groupBy(fn ($r) => $r['listing']->category_id)->map->count();
        $nodes = Category::where('sector_id', $sector->id)->where('is_active', true)->get(['id', 'parent_id']);
        $counts = [];

        foreach ($nodes as $n) {
            $counts[$n->id] = (int) ($own[$n->id] ?? 0);
        }
        // Walk each node's own count up through its parents.
        $parent = $nodes->pluck('parent_id', 'id');
        foreach ($own as $id => $n) {
            for ($up = $parent[$id] ?? null; $up !== null; $up = $parent[$up] ?? null) {
                $counts[$up] = ($counts[$up] ?? 0) + $n;
            }
        }

        return $counts;
    }

    /** One picture for a category: the first product photograph found at or below it. */
    private function picture(Category $c, $rows): ?string
    {
        $ids = $c->selfAndDescendantIds();
        $hit = $rows->first(fn ($r) => $r['listing']->photo_path !== null && in_array($r['listing']->category_id, $ids, true));

        return $hit ? $this->photoUrl($hit['listing']) : null;
    }

    private function photoUrl(Listing $l): string
    {
        return route('market.photo', ['listing' => $l->id, 'v' => substr(md5($l->photo_path.$l->updated_at), 0, 8)]);
    }

    /** The node being looked at: its name, the trail from the top, and the nodes just below it with counts and a picture each. */
    private function categoryNode(Category $c, $rows): array
    {
        $counts = $this->countsBelow($c->sector, $rows);
        $name = fn (Category $x) => app()->getLocale() === 'bn' && $x->name_bn ? $x->name_bn : $x->name;

        return [
            'slug' => $c->slug, 'name' => $name($c),
            'trail' => collect($c->trail())->map(fn (Category $x) => ['slug' => $x->slug, 'name' => $name($x)])->all(),
            'children' => $c->children->map(fn (Category $k) => ['slug' => $k->slug, 'name' => $name($k), 'count' => $counts[$k->id] ?? 0, 'image' => $this->picture($k, $rows)])->filter(fn ($k) => $k['count'] > 0)->values()->all(),
        ];
    }

    /** A shop's whole tree, as nested nodes with counts, for the side list. Nodes with nothing under them are left out. */
    private function categoryTree(Sector $sector, $rows): array
    {
        $counts = $this->countsBelow($sector, $rows);
        $all = Category::where('sector_id', $sector->id)->where('is_active', true)->orderBy('sort')->orderBy('name')->get()->groupBy('parent_id');
        $name = fn (Category $x) => app()->getLocale() === 'bn' && $x->name_bn ? $x->name_bn : $x->name;
        $grow = function ($parent) use (&$grow, $all, $counts, $name) {
            return ($all[$parent] ?? collect())->filter(fn (Category $n) => ($counts[$n->id] ?? 0) > 0)
                ->map(fn (Category $n) => ['slug' => $n->slug, 'name' => $name($n), 'count' => $counts[$n->id], 'children' => $grow($n->id)])->values()->all();
        };

        return $grow(null);
    }

    /**
     * A shop's front page, as the admin built it: the active sections in their order, each with the products or words it needs, already in the
     * viewer's language. A product shows once on the page (a shelf skips what an earlier shelf already showed). No sections means no front page.
     */
    private function storefront(Sector $sector, $rows, array $ranges, int $total, ?array $hero): ?array
    {
        $blocks = StorefrontBlock::where('sector_id', $sector->id)->where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        if ($blocks->isEmpty()) {
            return null;
        }

        $counts = $this->countsBelow($sector, $rows);
        $bn = app()->getLocale() === 'bn';
        $name = fn (Category $x) => $bn && $x->name_bn ? $x->name_bn : $x->name;
        $sold = MarketOrder::whereIn('listing_id', $rows->pluck('listing.id')->all())->where('status', '!=', 'cancelled')->selectRaw('listing_id, count(*) as n')->groupBy('listing_id')->pluck('n', 'listing_id');
        $top = Category::where('sector_id', $sector->id)->whereNull('parent_id')->where('is_active', true)->orderBy('sort')->get()
            ->map(fn (Category $c) => ['slug' => $c->slug, 'name' => $name($c), 'count' => $counts[$c->id] ?? 0, 'image' => $this->picture($c, $rows)])->filter(fn ($c) => $c['count'] > 0)->values()->all();

        $used = [];
        $pool = function (?string $source) use ($rows, $sold, $sector) {
            if ($source === 'best') {
                return $rows->sortByDesc(fn ($r) => [$sold[$r['listing']->id] ?? 0, $r['listing']->approved_at])->values();
            }
            if ($source === 'premium' || $source === 'top') {
                return $rows->sortByDesc('price')->values();
            }
            if (is_string($source) && str_starts_with($source, 'category:')) {
                $cat = Category::where('sector_id', $sector->id)->where('slug', substr($source, 9))->first();
                $ids = $cat ? $cat->selfAndDescendantIds() : [];

                return $rows->filter(fn ($r) => in_array($r['listing']->category_id, $ids, true))->sortByDesc(fn ($r) => $r['listing']->approved_at)->values();
            }
            if (is_numeric($source)) {
                return $rows->filter(fn ($r) => $r['listing']->id === (int) $source)->values();
            }

            return $rows->sortByDesc(fn ($r) => $r['listing']->approved_at)->values();
        };
        $take = function ($set, int $limit) use (&$used) {
            $out = $set->filter(fn ($r) => ! in_array($r['listing']->id, $used, true))->take($limit)->values();
            foreach ($out as $r) {
                $used[] = $r['listing']->id;
            }

            return $this->withStars($out->map(fn ($r) => $this->card($r))->values());
        };
        $image = fn (StorefrontBlock $b) => $b->image_path ? route('market.storefront-image', ['block' => $b->id, 'v' => substr(md5($b->image_path.$b->updated_at), 0, 8)]) : null;

        $out = [];

        foreach ($blocks as $b) {
            $row = ['id' => $b->id, 'kind' => $b->kind, 'title' => $b->say('title'), 'text' => $b->say('text'), 'button' => $b->say('button')];

            switch ($b->kind) {
                case 'hero':
                    $row['title'] = $row['title'] ?? ($hero ? ($bn && $hero['name_bn'] ? $hero['name_bn'] : $hero['name']) : $sector->name);
                    $row['text'] = $row['text'] ?? ($hero ? ($bn && $hero['tagline_bn'] ? $hero['tagline_bn'] : $hero['tagline']) : null);
                    $row['image'] = $image($b) ?? ($hero['image'] ?? null);
                    $row['count'] = $total;
                    break;
                case 'categories':
                    $row['categories'] = array_slice($top, 0, max(1, $b->item_limit));
                    if ($row['categories'] === []) {
                        continue 2;
                    }
                    break;
                case 'products':
                case 'premium':
                    $row['source'] = $b->source;
                    $row['products'] = $take($pool($b->source ?? ($b->kind === 'premium' ? 'premium' : 'fresh')), max(1, $b->item_limit));
                    if ($row['products']->isEmpty()) {
                        continue 2;
                    }
                    $row['products'] = $row['products']->all();
                    break;
                case 'banner':
                    $item = $take($pool($b->source ?? 'top'), 1)->first();
                    if (! $item) {
                        continue 2;
                    }
                    $row['product'] = $item;
                    $row['image'] = $image($b) ?? $item['photo'];
                    break;
                case 'budget':
                    $row['ranges'] = $ranges;
                    if ($ranges === []) {
                        continue 2;
                    }
                    break;
                case 'service':
                case 'faq':
                    $row['items'] = $b->itemList();
                    if ($row['items'] === []) {
                        continue 2;
                    }
                    break;
                case 'cta':
                    $row['count'] = $total;
                    break;
            }

            $out[] = $row;
        }

        return ['sector' => $sector->slug, 'count' => $total, 'blocks' => $out];
    }

    /** One section's own picture (the admin uploaded it), served from private storage. */
    public function storefrontImage(StorefrontBlock $block): StreamedResponse
    {
        abort_unless($block->image_path && Storage::exists($block->image_path) && $block->is_active && ($block->sector_id === null || $block->sector?->is_active), 404);

        return Storage::response($block->image_path, null, ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The marketplace home (/market with no shop chosen), as the admin built it: the active sections in order, each with its live data or its
     * words and pictures in the viewer's language. A product shows once on the page. No sections means the plain list.
     *
     * @param  Collection  $rows  every product open to this viewer
     * @return array<string, mixed>|null
     */
    private function home($rows, array $ranges, int $total, Collection $sectors): ?array
    {
        $blocks = StorefrontBlock::where('page', 'market')->where('is_active', true)->orderBy('sort')->orderBy('id')->get();

        if ($blocks->isEmpty()) {
            return null;
        }

        $bn = app()->getLocale() === 'bn';
        $sold = MarketOrder::whereIn('listing_id', $rows->pluck('listing.id')->all())->where('status', '!=', 'cancelled')->selectRaw('listing_id, count(*) as n')->groupBy('listing_id')->pluck('n', 'listing_id');
        $slugOf = fn ($r) => $r['listing']->sector->slug;
        $pool = function (?string $source) use ($rows, $sold, $slugOf) {
            if ($source === 'best') {
                return $rows->sortByDesc(fn ($r) => [$sold[$r['listing']->id] ?? 0, $r['listing']->approved_at])->values();
            }
            if ($source === 'premium' || $source === 'top') {
                return $rows->sortByDesc('price')->values();
            }
            if (is_string($source) && str_starts_with($source, 'sector:')) {
                return $rows->filter(fn ($r) => $slugOf($r) === substr($source, 7))->sortByDesc(fn ($r) => $r['listing']->approved_at)->values();
            }
            if (is_numeric($source)) {
                return $rows->filter(fn ($r) => $r['listing']->id === (int) $source)->values();
            }

            return $rows->sortByDesc(fn ($r) => $r['listing']->approved_at)->values();
        };
        $used = [];
        $take = function ($set, int $limit) use (&$used) {
            $out = $set->filter(fn ($r) => ! in_array($r['listing']->id, $used, true))->take($limit)->values();
            foreach ($out as $r) {
                $used[] = $r['listing']->id;
            }

            return $this->withStars($out->map(fn ($r) => $this->card($r))->values());
        };
        $image = fn (StorefrontBlock $b) => $b->image_path ? route('market.storefront-image', ['block' => $b->id, 'v' => substr(md5($b->image_path.$b->updated_at), 0, 8)]) : null;

        $shopPictures = Sector::where('is_active', true)->whereNotNull('hero_path')->get(['slug', 'hero_path'])->keyBy('slug');
        $shopPicture = fn (string $slug) => isset($shopPictures[$slug]) ? route('market.hero', ['slug' => $slug, 'w' => 960, 'v' => substr(md5($shopPictures[$slug]->hero_path), 0, 8)]) : null;

        $out = [];

        foreach ($blocks as $b) {
            $row = ['id' => $b->id, 'kind' => $b->kind, 'title' => $b->say('title'), 'text' => $b->say('text'), 'button' => $b->say('button'), 'link' => $b->link];

            switch ($b->kind) {
                case 'hero':
                    $row['image'] = $image($b);
                    $row['count'] = $total;
                    break;
                case 'showcase':
                case 'rail':
                    $row['count'] = $total;
                    // A tile with no picture of its own borrows the picture of the shop its link leads to.
                    $row['items'] = collect($b->itemList())->map(function ($i) use ($shopPicture) {
                        if ($i['image'] === null && preg_match('#^/market\?sector=([a-z0-9-]+)#', (string) $i['link'], $m)) {
                            $i['image'] = $shopPicture($m[1]);
                        }

                        return $i;
                    })->all();
                    if ($row['items'] === []) {
                        continue 2;
                    }
                    break;
                case 'feature':
                    $row['image'] = $image($b);
                    $row['items'] = $b->itemList();
                    break;
                case 'ticker':
                case 'numbers':
                    $row['items'] = $b->itemList();
                    if ($row['items'] === []) {
                        continue 2;
                    }
                    break;
                case 'premium':
                    $row['products'] = $take($pool($b->source ?? 'premium'), max(1, $b->item_limit))->all();
                    if ($row['products'] === []) {
                        continue 2;
                    }
                    break;
                case 'departments':
                    $row['departments'] = Sector::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
                        ->map(function (Sector $s) use ($sectors, $bn) {
                            $count = $sectors->firstWhere('slug', $s->slug)['count'] ?? 0;

                            return ['slug' => $s->slug, 'name' => $bn && $s->name_bn ? $s->name_bn : $s->name, 'tagline' => ($bn && $s->tagline_bn ? $s->tagline_bn : $s->tagline), 'count' => $count,
                                'image' => $s->hero_path ? route('market.hero', ['slug' => $s->slug, 'w' => 960, 'v' => substr(md5($s->hero_path), 0, 8)]) : null];
                        })->filter(fn ($d) => $d['count'] > 0)->take(max(1, $b->item_limit))->values()->all();
                    if ($row['departments'] === []) {
                        continue 2;
                    }
                    break;
                case 'products':
                    $row['source'] = $b->source;
                    $row['products'] = $take($pool($b->source ?? 'fresh'), max(1, $b->item_limit));
                    if ($row['products']->isEmpty()) {
                        continue 2;
                    }
                    $row['products'] = $row['products']->all();
                    break;
                case 'banner':
                    $item = $take($pool($b->source ?? 'top'), 1)->first();
                    if (! $item) {
                        continue 2;
                    }
                    $row['product'] = $item;
                    $row['image'] = $image($b) ?? $item['photo'];
                    break;
                case 'budget':
                    $row['ranges'] = $ranges;
                    if ($ranges === []) {
                        continue 2;
                    }
                    break;
                case 'promos':
                case 'collections':
                case 'service':
                case 'faq':
                case 'steps':
                    $row['items'] = $b->itemList();
                    if ($row['items'] === []) {
                        continue 2;
                    }
                    break;
                case 'sellers':
                    $row['sellers'] = $this->sellerShelf($rows, max(1, $b->item_limit));
                    if ($row['sellers'] === []) {
                        continue 2;
                    }
                    break;
                case 'reviews':
                    $row['reviews'] = Testimonial::where('is_active', true)->orderBy('sort')->orderByDesc('id')->limit(max(1, $b->item_limit))->get()
                        ->map(fn ($t) => ['name' => $t->name, 'place' => $t->place, 'quote' => $bn && $t->quote_bn ? $t->quote_bn : $t->quote, 'rating' => (int) $t->rating])->values()->all();
                    if ($row['reviews'] === []) {
                        continue 2;
                    }
                    break;
                case 'cta':
                    $row['count'] = $total;
                    break;
            }

            $out[] = $row;
        }

        return ['sector' => null, 'count' => $total, 'blocks' => $out];
    }

    /** The shops that have a page of their own, trusted ones first, each with a few of its products. */
    private function sellerShelf($rows, int $limit): array
    {
        return $rows->filter(fn ($r) => $r['listing']->seller && app(Shops::class)->hasPage($r['listing']->seller))
            ->groupBy(fn ($r) => $r['listing']->seller_id)
            ->map(function ($group) {
                $seller = $group->first()['listing']->seller;

                return [
                    'id' => $seller->id, 'name' => $this->market->sellerName($group->first()['listing']), 'products' => $group->count(), 'trusted' => $this->trustedSeller($seller),
                    'photos' => $group->map(fn ($r) => $r['listing']->photo_path !== null ? $this->photoUrl($r['listing']) : null)->filter()->take(3)->values()->all(),
                ];
            })
            ->sortBy([['trusted', 'desc'], ['products', 'desc']])->take($limit)->values()->all();
    }

    /** One picture of a collection or promo item, served from private storage while its section is shown. */
    public function storefrontItemImage(StorefrontBlock $block, int $index): StreamedResponse
    {
        $path = $block->items[$index]['image_path'] ?? null;
        abort_unless($path && str_starts_with($path, 'storefront/') && Storage::exists($path) && $block->is_active && ($block->sector_id === null || $block->sector?->is_active), 404);

        return Storage::response($path, null, ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** What the list and the page show of a listing. The seller's price and our commission are never in it. */
    private function card(array $r): array
    {
        /** @var Listing $l */
        $l = $r['listing'];

        return [
            'id' => $l->id, 'title' => $l->title, 'price' => $r['price'], 'was' => $r['was'] ?? null, 'sale_ends_at' => $r['sale_ends_at'] ?? null, 'sale_percent' => $r['sale_percent'] ?? null, 'premium' => $r['premium'] ?? false, 'near' => $r['near'] ?? false,
            'category' => $l->category?->name, 'category' => $l->category?->name, 'unit' => $l->sector->unit, 'sector' => $l->sector->slug, 'sector_name' => $l->sector->name,
            'blurb' => Str::limit(trim(strip_tags((string) $l->description)), 150), 'warranty' => $l->warranty_months, 'cod' => (bool) $l->cod_allowed,
            'photo' => $l->photo_path !== null ? route('market.photo', ['listing' => $l->id, 'v' => substr(md5($l->photo_path.$l->updated_at), 0, 8)]) : null, 'seller' => $this->market->sellerName($l),
            // A seller with a clean record (or whom we marked) wears the badge; it says nothing about who they are.
            'trusted' => $l->seller ? $this->trustedSeller($l->seller) : false,
            // Only a shop with its own page can be opened.
            'shop' => $l->seller && app(Shops::class)->hasPage($l->seller) ? $l->seller_id : null,
        ];
    }
}
