<?php

namespace App\Services\Mpo;

use App\Enums\AccountType;
use App\Models\MpoBid;
use App\Models\MpoListing;
use App\Models\Preorder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use App\Services\Pricing\PriceRounding;
use App\Services\Sales\PreOrders;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Blind bidding on MPO products (spec ch 10). A bulk buyer offers their own price as a discount off the printed price; several can.
 * Each bulk buyer sees only their own bid, the MPO sees the bids as "Anonymous 1, 2, 3" and accepts one, and only the owner sees who
 * bid. An MPO whose product does not sell is asked to raise the percentage, and one who accepts an order and does not deliver in
 * time has it cancelled and a failure counted on the profile.
 */
class MpoBidding
{
    public function __construct(private MpoService $mpos, private Messenger $messages, private ActivityLogger $activity, private PreOrders $preorders) {}

    // ------------------------------------------------------------------ the bulk buyer

    /** @throws StockException */
    public function bid(User $bidder, MpoListing $listing, float $percent, int $boxes): MpoBid
    {
        if ($bidder->account_type !== AccountType::Wholesaler || $bidder->isSuspended()) {
            throw new StockException('Only a bulk buyer can offer a price.');
        }

        if ($listing->status !== 'approved' || $listing->priceIn('wholesale') === null || $listing->mpo?->isSuspended()) {
            throw new StockException('This offer is no longer open.');
        }

        if ($percent <= 0 || $percent > 90) {
            throw new StockException('Ask for a discount between 0 and 90 percent.');
        }

        if ($boxes < 1 || $boxes > 100000) {
            throw new StockException('The quantity must be more than zero.');
        }

        $price = $this->priceAt($listing, $percent);

        $bid = MpoBid::updateOrCreate(
            ['mpo_listing_id' => $listing->id, 'bidder_id' => $bidder->id, 'status' => 'open'],
            ['boxes' => $boxes, 'percent' => $percent, 'box_price' => $price],
        );

        // The MPO is told a bid came, never by whom.
        if ($bid->wasRecentlyCreated && $listing->mpo) {
            $this->messages->send($listing->mpo, 'mpo_bid_new', ['name' => $this->name($listing)]);
        }

        return $bid;
    }

    public function withdraw(MpoBid $bid): void
    {
        if ($bid->status === 'open') {
            $bid->update(['status' => 'withdrawn']);
        }
    }

    /** What the printed price comes to after a discount, rounded the way every price is. */
    public function priceAt(MpoListing $listing, float $percent): float
    {
        return PriceRounding::round($this->printed($listing) * (1 - $percent / 100));
    }

    /** The discount the listed wholesale price already is, off the printed price. */
    public function currentPercent(MpoListing $listing): float
    {
        $printed = $this->printed($listing);
        $price = $listing->priceIn('wholesale');

        return $printed > 0 && $price !== null ? round((1 - $price / $printed) * 100, 2) : 0.0;
    }

    // ------------------------------------------------------------------ the MPO

    /**
     * The bids on one listing as the MPO sees them: a number and a percentage, no name.
     *
     * @return list<array{id: int, alias: string, percent: float, boxes: int}>
     */
    public function bidsForMpo(MpoListing $listing): array
    {
        return MpoBid::where('mpo_listing_id', $listing->id)->orderBy('id')->get()->values()
            ->map(fn (MpoBid $b, int $i) => ['id' => $b->id, 'alias' => 'Anonymous '.($i + 1), 'percent' => (float) $b->percent, 'boxes' => (int) $b->boxes, 'status' => $b->status])
            ->filter(fn ($b) => $b['status'] === 'open')->values()->map(fn ($b) => collect($b)->except('status')->all())->all();
    }

    /**
     * The MPO takes one bid. The order is made for that bulk buyer at their price, the other bids are declined, and both sides
     * see our account on it.
     *
     * @throws StockException
     */
    public function accept(MpoListing $listing, MpoBid $bid, User $mpo): Preorder
    {
        if ($listing->user_id !== $mpo->id || $bid->mpo_listing_id !== $listing->id) {
            throw new StockException('This bid is not for this product.');
        }

        if ($bid->status !== 'open' || $listing->status !== 'approved') {
            throw new StockException('This bid is no longer open.');
        }

        return DB::transaction(function () use ($listing, $bid) {
            $unit = $listing->medicine->packaging_type === 'box' ? 'box' : 'piece';
            $free = $listing->show_bonus && $listing->bonus_buy ? intdiv((int) $bid->boxes, $listing->bonus_buy) * (int) $listing->bonus_free : 0;
            $days = $listing->stock_status === 'pre_order' ? (int) $listing->arrive_days : 3;

            $pre = Preorder::create([
                'customer_id' => $bid->bidder_id, 'status' => 'accepted', 'promised_days' => $days, 'due_on' => today()->addDays($days), 'accepted_at' => now(),
                'mpo_listing_id' => $listing->id, 'box_price' => $bid->box_price, 'bonus_free' => $free ?: null,
            ]);
            $pre->lines()->create(['medicine_id' => $listing->medicine_id, 'unit' => $unit, 'quantity' => $bid->boxes]);

            $bid->update(['status' => 'accepted']);
            $others = MpoBid::where('mpo_listing_id', $listing->id)->where('status', 'open')->get();
            $others->each(fn (MpoBid $o) => $o->update(['status' => 'rejected']));

            $name = $this->name($listing);
            $this->messages->send($bid->bidder, 'mpo_bid_accepted', ['name' => $name], '/preorders/'.$pre->id);
            $others->each(fn (MpoBid $o) => $this->messages->send($o->bidder, 'mpo_bid_declined', ['name' => $name]));
            $this->activity->record('mpo.bid_accepted', $listing, ['bid' => $bid->id, 'percent' => (float) $bid->percent], $listing->mpo);

            return $pre;
        });
    }

    /**
     * The MPO raises the percentage themselves when a product is not selling: a deeper discount off the printed price in the
     * bulk market. It can only go up, never back, and the shop and public prices stay the owner's to set.
     *
     * @throws StockException
     */
    public function raise(MpoListing $listing, float $percent, User $mpo): MpoListing
    {
        if ($listing->user_id !== $mpo->id || $listing->status !== 'approved' || $listing->priceIn('wholesale') === null) {
            throw new StockException('You can only do this on a product that is on the market for bulk buyers.');
        }

        if ($percent <= $this->currentPercent($listing) || $percent > 90) {
            throw new StockException('The new percentage must be higher than the one now, and at most 90.');
        }

        $listing->update(['price_wholesale' => $this->priceAt($listing, $percent)]);
        $this->activity->record('mpo.percent_raised', $listing, ['percent' => $percent], $mpo);

        return $listing->fresh();
    }

    // ------------------------------------------------------------------ the quiet jobs

    /** A product on the market for bulk buyers that nobody has ordered or won for some days: ask the MPO to raise the percentage. */
    public function tellStale(): int
    {
        $days = max(1, (int) Setting::read('mpo.stale_days', 7));
        $count = 0;

        MpoListing::where('status', 'approved')->where('approved_at', '<', now()->subDays($days))->whereJsonContains('markets', 'wholesale')
            ->where(fn ($q) => $q->whereNull('stale_told_at')->orWhere('stale_told_at', '<', now()->subDays($days)))
            ->with('mpo', 'medicine')->get()->each(function (MpoListing $l) use ($days, &$count) {
                $sold = Preorder::where('mpo_listing_id', $l->id)->where('created_at', '>=', now()->subDays($days))->exists();

                if ($sold || ! $l->mpo || $l->mpo->isSuspended()) {
                    return;
                }

                $this->messages->send($l->mpo, 'mpo_stale', ['name' => $this->name($l)]);
                $l->update(['stale_told_at' => now()]);
                $count++;
            });

        return $count;
    }

    /**
     * An order from an MPO's product that did not arrive in time is cancelled, the buyer is told, and a failure is counted on the
     * MPO. The time allowed beyond the promised day is a setting.
     */
    public function expireOrders(): int
    {
        $grace = max(0, (int) Setting::read('mpo.grace_days', 2));
        $count = 0;

        Preorder::whereNotNull('mpo_listing_id')->where('status', 'accepted')->whereDate('due_on', '<', today()->subDays($grace))->with('listing.mpo')->get()
            ->each(function (Preorder $p) use (&$count) {
                $this->preorders->cancelBySeller($p, __('It did not arrive in time.'), null);
                $p->listing?->mpo?->increment('mpo_failed');
                $count++;
            });

        return $count;
    }

    // ------------------------------------------------------------------ helpers

    /** @return Collection<int, MpoBid> a bulk buyer's own open bids; nobody else's */
    public function mine(User $bidder): Collection
    {
        return MpoBid::where('bidder_id', $bidder->id)->where('status', 'open')->get()->keyBy('mpo_listing_id');
    }

    private function printed(MpoListing $listing): float
    {
        $m = $listing->medicine;

        return (float) ($m->packaging_type === 'box' ? $m->mrp_box : $m->mrp_piece);
    }

    private function name(MpoListing $listing): string
    {
        $m = $listing->medicine ?? $listing->load('medicine')->medicine;

        return trim($m->brand_name.' '.$m->strength);
    }
}
