<?php

namespace App\Services\Market;

use App\Models\CatalogProduct;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;

/**
 * The central product catalog (spec ch 13). One name for one product, so the same phone from ten sellers is one page with ten prices, whatever
 * way each seller would have spelled it. Where the owner asks for it a seller must pick from the catalog; a product that is not there is asked
 * for and waits for the owner.
 *
 * The order of sellers on one product is not the lowest price alone: price and delivery charge together, made a little dearer for a seller with
 * a poor record (returns, "not received", slow to send). Otherwise someone would drop the price and raise the delivery charge.
 */
class Catalog
{
    /** How much a poor record adds to what a buyer really pays, at the worst (a quarter). */
    public const RECORD_WEIGHT = 0.25;

    public function __construct(private Marketplace $market, private SellerReports $reports, private Messenger $messages) {}

    private function exists(Sector $sector, string $name): bool
    {
        return CatalogProduct::where('sector_id', $sector->id)->whereIn('status', ['approved', 'pending'])->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
    }

    /** @return Collection<int, CatalogProduct> */
    public function choices(Sector $sector, string $q = ''): Collection
    {
        $q = mb_strtolower(trim($q));

        return CatalogProduct::where('sector_id', $sector->id)->where('status', 'approved')->orderBy('name')->get()
            ->when($q !== '', fn ($c) => $c->filter(fn (CatalogProduct $p) => str_contains(mb_strtolower($p->name.' '.$p->name_bn), $q)))->values();
    }

    /** @throws StockException */
    public function add(Sector $sector, string $name, ?string $nameBn, User $by): CatalogProduct
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) < 2) {
            throw new StockException('Write the name of the product.');
        }

        if ($this->exists($sector, $name)) {
            throw new StockException('This product is already in the catalog.');
        }

        return CatalogProduct::create(['sector_id' => $sector->id, 'name' => mb_substr($name, 0, 150), 'name_bn' => $nameBn ? mb_substr(trim($nameBn), 0, 150) : null, 'status' => 'approved', 'decided_by' => $by->id]);
    }

    /** A seller asks for a product that is not in the list. It waits for us. @throws StockException */
    public function request(User $seller, Sector $sector, string $name): CatalogProduct
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) < 2) {
            throw new StockException('Write the name of the product.');
        }

        if ($this->exists($sector, $name)) {
            throw new StockException('This product is already in the catalog. Pick it from the list.');
        }

        if (CatalogProduct::where('requested_by', $seller->id)->where('status', 'pending')->count() >= 10) {
            throw new StockException('You have asked for enough. Please wait for us to answer.');
        }

        $product = CatalogProduct::create(['sector_id' => $sector->id, 'name' => mb_substr($name, 0, 150), 'status' => 'pending', 'requested_by' => $seller->id]);

        foreach (User::withPermission('customers') as $reviewer) {
            Notice::create(['user_id' => $reviewer->id, 'kind' => 'catalog_new', 'body' => mb_substr($product->name, 0, 290)]);
        }

        return $product;
    }

    public function approve(CatalogProduct $product, User $by, ?string $name = null): CatalogProduct
    {
        if ($product->status !== 'pending') {
            throw new StockException('This was already looked at.');
        }

        $name = $name !== null && trim($name) !== '' ? trim($name) : $product->name;

        if ($name !== $product->name && $this->exists($product->sector, $name)) {
            throw new StockException('This product is already in the catalog.');
        }

        $product->update(['status' => 'approved', 'name' => mb_substr($name, 0, 150), 'decided_by' => $by->id]);

        if ($product->requester) {
            $this->messages->send($product->requester, 'catalog_decided', ['name' => $product->name, 'note' => ''], '/wholesale/sell');
        }

        return $product->fresh();
    }

    public function reject(CatalogProduct $product, User $by, string $note): CatalogProduct
    {
        if ($product->status !== 'pending') {
            throw new StockException('This was already looked at.');
        }

        $product->update(['status' => 'rejected', 'decided_by' => $by->id, 'note' => mb_substr(trim($note), 0, 300)]);

        if ($product->requester) {
            $this->messages->send($product->requester, 'catalog_decided', ['name' => $product->name, 'note' => $product->note], '/wholesale/sell');
        }

        return $product->fresh();
    }

    /** May this seller list this catalog product in this sector? Only an approved one of that sector. @throws StockException */
    public function check(Sector $sector, ?int $catalogId): ?CatalogProduct
    {
        if ($catalogId === null) {
            if ($sector->catalog_required) {
                throw new StockException('Pick the product from the catalog, or ask us to add it.');
            }

            return null;
        }

        return CatalogProduct::where('status', 'approved')->where('sector_id', $sector->id)->find($catalogId) ?? throw new StockException('Pick the product from the catalog, or ask us to add it.');
    }

    // ------------------------------------------------------------------ one product, many sellers

    /**
     * Every seller's price for this product, the best first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function offers(CatalogProduct $product, ?User $viewer): Collection
    {
        $ranks = $this->reports->ranks();

        $rows = $this->market->visible($viewer)->filter(fn (array $r) => $r['listing']->catalog_product_id === $product->id)
            ->map(function (array $r) use ($ranks) {
                /** @var Listing $l */
                $l = $r['listing'];
                $rank = $ranks[$l->seller_id] ?? ['score' => SellerReports::UNPROVEN_SCORE, 'proven' => false];

                return [
                    'listing' => $l, 'price' => $r['price'], 'delivery' => (float) $l->delivery_charge, 'rating' => SellerReports::label($rank['score'], $rank['proven']),
                    'cost' => $this->cost($r['price'], (float) $l->delivery_charge, $rank['score']),
                ];
            });

        return $rows->sortBy('cost')->values();
    }

    /** What the buyer really pays, made a little dearer for a poorer record: 100 is no change, 0 adds a quarter. */
    public function cost(float $price, float $delivery, int $score): float
    {
        return round(($price + $delivery) * (1 + (100 - max(0, min(100, $score))) / 100 * self::RECORD_WEIGHT), 2);
    }
}
