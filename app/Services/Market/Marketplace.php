<?php

namespace App\Services\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\ImageCompressor;
use App\Services\Messages\Messenger;
use App\Services\Pricing\PriceWarning;
use App\Services\Stock\PriceWarningException;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The marketplace beyond medicine (spec ch 13, 14, 15). A trade account can sell in the sectors it was given; each listing waits for us,
 * who set our commission before it shows; buyers pay the seller's price plus that commission and never see which part is which; every
 * product carries our name unless the owner switched the seller's own name on.
 */
class Marketplace
{
    public function __construct(private Messenger $messages, private ImageCompressor $images, private SellerTrust $trust, private SellerDeposits $deposits) {}

    // ------------------------------------------------------------------ who may sell

    /** Only a trade account sells here: public and trade buyers can see its products, a shop (paikari) can never sell (spec ch 14). */
    public function canSell(User $user): bool
    {
        return $user->account_type === AccountType::Wholesaler && ! $user->isSuspended() && ! $user->sellingStopped();
    }

    /** The sectors this seller may list in: the categories they were given, and never medicine, which has its own system. */
    public function sectorsFor(User $user): Collection
    {
        $slugs = array_values(array_diff((array) $user->categories, ['medicine']));

        return Sector::where('is_active', true)->whereIn('slug', $slugs)->orderBy('sort')->orderBy('name')->get();
    }

    // ------------------------------------------------------------------ listing

    /**
     * @param  array{sector_id: int, title: string, description?: ?string, public_price?: ?float, wholesale_price?: ?float, min_qty?: ?float, delivery_charge?: ?float, stock?: ?float, commission_request?: ?string}  $data
     *
     * @throws StockException
     */
    public function submit(User $seller, array $data, ?UploadedFile $photo = null): Listing
    {
        // When the owner has switched the deposit on, a seller who has not kept enough with us cannot send in new products (spec ch 14).
        $this->deposits->guard($seller);

        $sector = $this->sectorFor($seller, (int) $data['sector_id']);
        $this->checkPrices($data, $sector, $seller);
        $data = $this->forSector($sector, $data, true);
        $catalog = app(Catalog::class)->check($sector, isset($data['catalog_product_id']) && $data['catalog_product_id'] !== '' ? (int) $data['catalog_product_id'] : null);

        $listing = Listing::create($this->fields($data) + [
            'seller_id' => $seller->id, 'sector_id' => $sector->id, 'catalog_product_id' => $catalog?->id, 'status' => 'pending', 'submitted_at' => now(),
            'photo_path' => $photo ? $this->images->store($photo, 'market') : null, 'flag' => $this->flagFor($data),
        ]);

        $listing->extraSectors()->sync($this->extraSectorIds($seller, $sector, $data));

        foreach (User::withPermission('customers') as $reviewer) {
            Notice::create(['user_id' => $reviewer->id, 'kind' => 'listing_new', 'body' => mb_substr($listing->title, 0, 290)]);
        }

        return $listing;
    }

    /**
     * The seller changes a listing. The words, the picture and the sector are what we approved, so changing them sends it back to
     * be read again; the price, the delivery charge and the stock are the seller's own business and change at once.
     *
     * @throws StockException
     */
    public function update(Listing $listing, array $data, ?UploadedFile $photo = null): Listing
    {
        $sector = $this->sectorFor($listing->seller, (int) ($data['sector_id'] ?? $listing->sector_id));
        // A price far below the one before, or zero, must be confirmed (spec ch 04: 50 instead of 500).
        if (! ($data['confirm_price'] ?? false)) {
            $warn = app(PriceWarning::class);
            foreach (['public_price', 'wholesale_price'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '' && $listing->{$field} !== null
                    && ($drop = $warn->drop((float) $listing->{$field}, (float) $data[$field])) !== null) {
                    throw new PriceWarningException($warn->message($drop));
                }
            }
        }

        $merged = array_merge($listing->only(['public_price', 'wholesale_price', 'min_qty', 'delivery_charge', 'stock']), $data);
        $this->checkPrices($merged, $sector, $listing->seller);
        $data = $this->forSector($sector, $data + ['expires_on' => $listing->expires_on?->toDateString()], false);

        // The catalog product (spec ch 13): a new one, or a new sector, is read again like a new listing.
        $catalogChanged = false;
        if (array_key_exists('catalog_product_id', $data) || $sector->id !== $listing->sector_id) {
            $wanted = array_key_exists('catalog_product_id', $data) ? ($data['catalog_product_id'] !== null && $data['catalog_product_id'] !== '' ? (int) $data['catalog_product_id'] : null) : null;
            $catalog = app(Catalog::class)->check($sector, $wanted);
            $catalogChanged = $catalog?->id !== $listing->catalog_product_id;
            $data['catalog_product_id'] = $catalog?->id;
        }

        $extras = array_key_exists('extra_sector_ids', $data) ? $this->extraSectorIds($listing->seller, $sector, $data) : null;
        $extrasChanged = $extras !== null && $extras !== $listing->extraSectors()->pluck('sectors.id')->sort()->values()->all();

        $reread = $sector->id !== $listing->sector_id || $extrasChanged || $catalogChanged || $photo !== null
            || (isset($data['title']) && trim($data['title']) !== $listing->title)
            || (array_key_exists('description', $data) && trim((string) $data['description']) !== trim((string) $listing->description));

        $changes = $this->fields($data) + ['sector_id' => $sector->id];
        if ($photo) {
            $this->forget($listing->photo_path);
            $changes['photo_path'] = $this->images->store($photo, 'market');
        }
        if ($reread && $listing->status === 'published') {
            $changes += ['status' => 'pending', 'submitted_at' => now(), 'flag' => $this->flagFor($data + $listing->only(['title', 'description']))];
        } elseif ($listing->status === 'rejected') {
            $changes += ['status' => 'pending', 'submitted_at' => now(), 'review_note' => null, 'flag' => $this->flagFor($data + $listing->only(['title', 'description']))];
        }

        $listing->update($changes);

        if ($extras !== null) {
            $listing->extraSectors()->sync($extras);
        }

        return $listing->fresh();
    }

    public function pause(Listing $listing, bool $paused): void
    {
        if (! in_array($listing->status, ['published', 'paused'], true)) {
            throw new StockException('Only a published listing can be paused.');
        }

        $listing->update(['status' => $paused ? 'paused' : 'published']);
    }

    public function remove(Listing $listing): void
    {
        // A listing with orders on the way is only hidden, never deleted (spec ch 14).
        if (MarketOrder::where('listing_id', $listing->id)->whereIn('status', MarketOrder::ACTIVE)->exists()) {
            throw new StockException('This listing has orders on the way. Pause it instead.');
        }

        $this->forget($listing->photo_path);
        app(ListingVariants::class)->forgetPhotos($listing);
        app(ListingPhotos::class)->forgetAll($listing);
        $listing->delete();
    }

    // ------------------------------------------------------------------ our side

    /** Publish it with our commission on top of the seller's price. */
    public function approve(Listing $listing, User $by, string $kind, float $value, ?string $note = null): void
    {
        if (! in_array($listing->status, ['pending'], true)) {
            throw new StockException('This listing was already looked at.');
        }

        if (! in_array($kind, ['percent', 'taka'], true) || $value < 0 || ($kind === 'percent' && $value > 90)) {
            throw new StockException('The commission is a percent up to 90, or an amount in taka.');
        }

        $firstTime = $listing->approved_at === null;

        $listing->update([
            'status' => 'published', 'commission_kind' => $kind, 'commission_value' => $value, 'reviewed_by' => $by->id,
            'review_note' => $note ? mb_substr(trim($note), 0, 300) : null, 'approved_at' => now(), 'commission_request' => null,
        ]);
        $this->messages->send($listing->seller, 'listing_approved', ['name' => $listing->title], '/wholesale/sell');

        if ($firstTime) {
            app(Shops::class)->announce($listing->fresh('seller'));
        }
    }

    public function reject(Listing $listing, User $by, string $note): void
    {
        if ($listing->status !== 'pending') {
            throw new StockException('This listing was already looked at.');
        }

        $listing->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'review_note' => mb_substr(trim($note), 0, 300)]);
        $this->messages->send($listing->seller, 'listing_rejected', ['name' => $listing->title, 'note' => $note], '/wholesale/sell');
    }

    /** The commission on a published listing can be changed by us at any time, for example after the seller asked for less. */
    public function setCommission(Listing $listing, string $kind, float $value): void
    {
        if (! in_array($kind, ['percent', 'taka'], true) || $value < 0 || ($kind === 'percent' && $value > 90)) {
            throw new StockException('The commission is a percent up to 90, or an amount in taka.');
        }

        $listing->update(['commission_kind' => $kind, 'commission_value' => $value, 'commission_request' => null]);
    }

    /** How many are waiting, and for how long; the oldest first so none is forgotten. */
    public function queue(): Collection
    {
        return Listing::where('status', 'pending')->with(['seller:id,name,business_name', 'sector:id,name,unit'])->oldest('submitted_at')->get()
            ->map(fn (Listing $l) => ['listing' => $l, 'waiting_days' => (int) $l->submitted_at->diffInDays(now()), 'no_photo' => $l->photo_path === null]);
    }

    // ------------------------------------------------------------------ what a buyer sees

    /** The words that name the seller: ours, unless the owner has switched the seller's own name on (spec ch 05). */
    public function sellerName(Listing $listing): string
    {
        $seller = $listing->seller;

        return $seller && $seller->show_shop_name ? ($seller->business_name ?: $seller->name) : (string) config('brand.name', config('app.name'));
    }

    /** May a buyer collect this one? The seller allows it, their name is shown, and they have said where (spec ch 20: only for sellers whose name and place show). */
    public function canPickup(Listing $listing): bool
    {
        $seller = $listing->seller;

        return $listing->pickup && $seller !== null && $seller->show_shop_name && filled($seller->shop_address);
    }

    /** Can this viewer see this listing at all? A bulk-only listing is invisible to everyone who is not that kind of buyer. */
    public function priceFor(Listing $listing, ?User $viewer, ?ListingVariant $variant = null): ?array
    {
        // Past its date it is not sold (spec ch 15: expiry is a must for some kinds of business).
        if ($listing->expires_on && $listing->expires_on->isPast() && ! $listing->expires_on->isToday()) {
            return null;
        }

        if ($listing->status !== 'published' || ! $listing->sector?->is_active || $listing->seller?->isSuspended() || $listing->seller?->sellingStopped()) {
            return null;
        }

        $bulkBuyer = $viewer?->account_type === AccountType::Wholesaler && in_array($listing->sector->slug, (array) $viewer->categories, true);

        // One colour or size may have a price of its own; if not, the product's price applies (spec ch 15).
        $wholesale = $variant?->wholesale_price ?? $listing->wholesale_price;
        $public = $variant?->public_price ?? $listing->public_price;

        if ($bulkBuyer && $wholesale !== null) {
            $base = (float) $wholesale;
            $audience = 'wholesale';
        } elseif ($public !== null && ($viewer === null || $viewer->account_type !== AccountType::Mpo)) {
            $base = (float) $public;
            $audience = 'public';
        } else {
            return null;
        }

        // A flash sale takes a percent off the seller's price for a while; it is for the public price, and the buyer is shown what it was.
        $was = $saleEnds = $percent = null;
        $sale = $audience === 'public' ? $listing->liveSale() : null;

        if ($sale !== null) {
            $was = $listing->withCommission($base);
            $base = round($base * (100 - $sale->percent) / 100, 2);
            $saleEnds = $sale->ends_at->toIso8601String();
            $percent = $sale->percent;
        }

        return [
            'seller_price' => $base, 'price' => $listing->withCommission($base), 'audience' => $audience, 'min_qty' => $audience === 'wholesale' ? (float) $listing->min_qty : 1.0,
            'was' => $was, 'sale_ends_at' => $saleEnds, 'sale_percent' => $percent,
        ];
    }

    public function isPremium(Listing $listing, float $price): bool
    {
        $over = $listing->sector?->premium_over;

        return $over !== null && $price >= (float) $over;
    }

    /** The price steps buyers can pick as a budget, in taka (a setting). */
    public function steps(): array
    {
        $steps = collect(explode(',', (string) Setting::read('market.budget_steps', '200,500,1000,2000,5000,10000')))
            ->map(fn ($s) => (float) trim($s))->filter(fn ($s) => $s > 0)->unique()->sort()->values()->all();

        return $steps ?: [200.0, 500.0, 1000.0, 2000.0, 5000.0, 10000.0];
    }

    /** The budget ranges that have something in them. An empty range is never offered, so an empty page never happens (spec ch 13). */
    public function ranges(Collection $prices): array
    {
        $steps = $this->steps();
        $bounds = [[0.0, $steps[0]]];
        for ($i = 1; $i < count($steps); $i++) {
            $bounds[] = [$steps[$i - 1], $steps[$i]];
        }
        $bounds[] = [end($steps), null];

        return collect($bounds)->filter(fn ($b) => $prices->contains(fn ($p) => $p >= $b[0] && ($b[1] === null || $p < $b[1])))
            ->map(fn ($b) => ['from' => $b[0], 'to' => $b[1]])->values()->all();
    }

    /**
     * The listings this viewer can see, priced for them, nearest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function visible(?User $viewer, ?string $sector = null): Collection
    {
        return Listing::shown()->with(['sector', 'seller', 'sale'])
            ->when($sector, fn ($q) => $q->where(fn ($w) => $w->whereHas('sector', fn ($s) => $s->where('slug', $sector))->orWhereHas('extraSectors', fn ($s) => $s->where('slug', $sector))))
            ->get()
            ->map(function (Listing $l) use ($viewer) {
                $p = $this->priceFor($l, $viewer);

                return $p ? ['listing' => $l] + $p + ['premium' => $this->isPremium($l, $p['price']), 'near' => $viewer?->area && $l->seller?->area && mb_strtolower($viewer->area) === mb_strtolower($l->seller->area)] : null;
            })->filter()->values();
    }

    // ------------------------------------------------------------------ helpers

    /** The other categories a seller chose: only their own, never the main one twice. @return array<int, int> */
    private function extraSectorIds(User $seller, Sector $main, array $data): array
    {
        $own = $this->sectorsFor($seller)->pluck('id')->all();
        $ids = collect(is_array($data['extra_sector_ids'] ?? null) ? $data['extra_sector_ids'] : explode(',', (string) ($data['extra_sector_ids'] ?? '')))->filter(fn ($i) => $i !== '' && $i !== null)->map(fn ($i) => (int) $i)->unique()->reject(fn ($i) => $i === $main->id)->values();

        if ($ids->contains(fn ($i) => ! in_array($i, $own, true))) {
            throw new StockException('Pick one of your own kinds of business.');
        }

        return $ids->sort()->values()->all();
    }

    private function sectorFor(User $seller, int $id): Sector
    {
        if (! $this->canSell($seller)) {
            throw new StockException('Only a trade account can sell here.');
        }

        return $this->sectorsFor($seller)->firstWhere('id', $id) ?? throw new StockException('Pick one of your own kinds of business.');
    }

    private function checkPrices(array $data, Sector $sector, User $seller): void
    {
        $pub = $data['public_price'] ?? null;
        $whole = $data['wholesale_price'] ?? null;

        if (($pub === null || $pub === '') && ($whole === null || $whole === '')) {
            throw new StockException('Give a price for the public, for trade buyers, or both.');
        }

        foreach ([$pub, $whole] as $price) {
            if ($price !== null && $price !== '' && (float) $price <= 0) {
                throw new StockException('A price must be more than zero.');
            }
        }

        // A new seller starts with low-value products; the limit lifts after enough clean orders (spec ch 14: gradual trust).
        $ceiling = $this->trust->ceiling($seller);
        $dearest = max((float) ($pub ?: 0), (float) ($whole ?: 0));

        if ($ceiling !== null && $dearest > $ceiling) {
            throw new StockException(__('New sellers can list products up to {limit} taka until they have {n} finished orders with no complaint.', ['limit' => rtrim(rtrim(number_format($ceiling, 2, '.', ''), '0'), '.'), 'n' => $this->trust->needed()]));
        }

        if (isset($data['stock']) && $data['stock'] !== '' && ! $sector->allow_fraction && floor((float) $data['stock']) != (float) $data['stock']) {
            throw new StockException('This kind of business counts in whole units.');
        }
    }

    /**
     * Only the fields this kind of business uses are kept; the rest are cleared. An expiry date is a must where the sector asks for it.
     *
     * @throws StockException
     */
    private function forSector(Sector $sector, array $data, bool $creating): array
    {
        foreach ([
            'measurements' => ['item_height', 'item_length', 'item_width', 'item_size_unit'], 'weight' => ['item_weight'], 'expiry' => ['expires_on'], 'warranty' => ['warranty_months'],
        ] as $field => $columns) {
            if (! $sector->uses($field)) {
                foreach ($columns as $c) {
                    if (array_key_exists($c, $data) || ! $creating) {
                        $data[$c] = $c === 'item_size_unit' ? 'inch' : null;
                    }
                }
            }
        }

        if ($sector->uses('expiry')) {
            $date = $data['expires_on'] ?? null;

            if ($date === null || $date === '') {
                throw new StockException('Write the expiry date.');
            }

            if (Carbon::parse($date)->isPast() && ! Carbon::parse($date)->isToday()) {
                throw new StockException('This has already expired.');
            }
        }

        return $data;
    }

    /** A new seller's grid prices are held to the same limit as the product's own (spec ch 14). */
    public function guardCeiling(User $seller, float $price): void
    {
        $ceiling = $this->trust->ceiling($seller);

        if ($ceiling !== null && $price > $ceiling) {
            throw new StockException(__('New sellers can list products up to {limit} taka until they have {n} finished orders with no complaint.', ['limit' => rtrim(rtrim(number_format($ceiling, 2, '.', ''), '0'), '.'), 'n' => $this->trust->needed()]));
        }
    }

    /** Only the fields a seller may set. Commission and status are ours. */
    private function fields(array $data): array
    {
        $out = [];
        foreach (['title', 'description', 'public_price', 'wholesale_price', 'min_qty', 'delivery_charge', 'stock', 'commission_request', 'cost_price', 'low_stock_at', 'item_height', 'item_length', 'item_width', 'item_size_unit', 'item_weight', 'expires_on', 'warranty_months', 'seo_title', 'seo_description', 'return_days', 'return_note', 'preorder_days', 'alt_names', 'catalog_product_id', 'pickup', 'cod_allowed'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $out[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }
        foreach (['min_qty' => 1, 'delivery_charge' => 0] as $key => $default) {
            if (array_key_exists($key, $out) && $out[$key] === null) {
                $out[$key] = $default;
            }
        }

        return $out;
    }

    /** A word from our prohibited list that the words of this listing contain, so the reviewer sees it at once (spec ch 15). */
    private function flagFor(array $data): ?string
    {
        $words = collect(preg_split('/[,\n]+/u', (string) Setting::read('market.prohibited_words', '')))->map(fn ($w) => mb_strtolower(trim($w)))->filter();
        $text = mb_strtolower(($data['title'] ?? '').' '.($data['description'] ?? ''));

        return $words->first(fn ($w) => str_contains($text, $w));
    }

    private function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::delete($path);
        }
    }
}
