<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a seller is shown about their own business, and how sellers are ranked (spec ch 14: reports for sellers). When sellers understand
 * their business they do better and our commission rises: which products are looked at but not bought, which earns most, which month
 * sales rose. The rank rewards sellers who ship fast and have few returns; a better rank puts their products higher.
 */
class SellerReports
{
    /** A seller needs this many finished orders before a rank means anything. Until then they are in the middle, not at the bottom. */
    public const PROVEN = 3;

    public const UNPROVEN_SCORE = 70;

    /** Looked at this many times and never ordered: worth a look at the price or the picture. */
    public const LOOKED_AT = 10;

    /**
     * Each listing of this seller: how many looked, how many ordered, what it earned.
     *
     * @return array<int, array<string, mixed>>
     */
    public function products(User $seller, int $days = 90, ?int $sectorId = null): array
    {
        $since = now()->subDays($days);
        $listings = Listing::where('seller_id', $seller->id)->whereIn('status', ['published', 'paused'])->when($sectorId, fn ($q) => $q->where('sector_id', $sectorId))->with('variants')->get();

        $views = DB::table('behavior_events')->where('type', 'listing_view')->whereIn('subject_id', $listings->pluck('id'))->where('created_at', '>=', $since)
            ->groupBy('subject_id')->pluck(DB::raw('count(distinct visitor)'), 'subject_id');
        $orders = MarketOrder::where('seller_id', $seller->id)->where('created_at', '>=', $since)->whereNotIn('status', ['cancelled'])->whereIn('listing_id', $listings->pluck('id'))->get()->groupBy('listing_id');

        return $listings->map(function (Listing $l) use ($views, $orders) {
            $mine = $orders->get($l->id, collect());
            $done = $mine->where('status', 'delivered');
            $seen = (int) ($views[$l->id] ?? 0);
            $earned = round((float) $done->sum(fn (MarketOrder $o) => (float) $o->quantity * (float) $o->seller_price), 2);
            $sold = round((float) $done->sum('quantity'), 2);
            // What is left: the grid's cells added up, or the product's own number; null when the seller does not count it.
            $left = $l->variants->isNotEmpty() ? ($l->variants->contains(fn ($v) => $v->stock === null) ? null : (float) $l->variants->sum('stock')) : ($l->stock !== null ? (float) $l->stock : null);
            $cost = $l->cost_price !== null ? (float) $l->cost_price : null;

            return [
                'id' => $l->id, 'title' => $l->title, 'views' => $seen, 'orders' => $mine->count(), 'sold' => $sold, 'earned' => $earned, 'left' => $left, 'low' => $left !== null && $l->low_stock_at !== null && $left <= (float) $l->low_stock_at,
                // The seller's own cost is private and optional: with it they can see what they really made.
                'cost' => $cost, 'profit' => $cost !== null ? round($earned - $sold * $cost, 2) : null,
                // Seen but not bought: the picture comes first (a listing without one rarely sells), then the price.
                'hint' => $mine->isEmpty() && $seen >= self::LOOKED_AT ? ($l->photo_path === null ? 'photo' : 'price') : null,
            ];
        })->sortByDesc('earned')->values()->all();
    }

    /**
     * Sales by month for the last twelve months, with whether each rose on the one before.
     *
     * @return array<int, array{month: string, sales: float, orders: int, change: ?float}>
     */
    public function months(User $seller, int $count = 12, ?int $sectorId = null): array
    {
        $start = now()->startOfMonth()->subMonths($count - 1);
        $rows = MarketOrder::where('seller_id', $seller->id)->where('status', 'delivered')->where('delivered_at', '>=', $start)
            ->when($sectorId, fn ($q) => $q->whereIn('listing_id', Listing::where('seller_id', $seller->id)->where('sector_id', $sectorId)->select('id')))
            ->get(['quantity', 'seller_price', 'commission', 'delivered_at'])
            ->groupBy(fn ($o) => $o->delivered_at->format('Y-m'));

        // What came back in each month, for the same business (spec ch 03: returns are shown per business too).
        $back = MarketOrder::where('seller_id', $seller->id)->where('status', 'returned')->where('returned_at', '>=', $start)
            ->when($sectorId, fn ($q) => $q->whereIn('listing_id', Listing::where('seller_id', $seller->id)->where('sector_id', $sectorId)->select('id')))
            ->get(['returned_at'])->groupBy(fn ($o) => $o->returned_at->format('Y-m'));

        $out = [];
        $prev = null;
        for ($i = 0; $i < $count; $i++) {
            $key = $start->copy()->addMonths($i)->format('Y-m');
            $set = $rows->get($key, collect());
            $sales = round((float) $set->sum(fn ($o) => (float) $o->quantity * (float) $o->seller_price), 2);
            $out[] = ['month' => $key, 'sales' => $sales, 'commission' => round((float) $set->sum('commission'), 2), 'orders' => $set->count(), 'returns' => $back->get($key, collect())->count(), 'change' => $prev !== null && $prev > 0 ? round(($sales - $prev) / $prev * 100, 1) : null];
            $prev = $sales;
        }

        return $out;
    }

    // ------------------------------------------------------------------ the rank

    /**
     * The score of every seller who has orders, 0 to 100. A seller is judged on the last 90 days: how fast they send (from order to sent),
     * how many came back, how many buyers said it did not arrive.
     *
     * @return array<int, array{score: int, proven: bool, ship_hours: ?float, return_rate: float, missing_rate: float, finished: int}>
     */
    public function ranks(): array
    {
        $since = now()->subDays(90);
        $out = [];

        MarketOrder::where('created_at', '>=', $since)->whereNotIn('status', ['cancelled', 'placed', 'accepted'])->get(['seller_id', 'status', 'created_at', 'shipped_at'])
            ->groupBy('seller_id')->each(function ($rows, $sellerId) use (&$out) {
                $finished = $rows->count();
                $returned = $rows->where('status', 'returned')->count();
                $missing = $rows->where('status', 'not_received')->count();
                $sent = $rows->filter(fn ($o) => $o->shipped_at !== null);
                $hours = $sent->isEmpty() ? null : round($sent->avg(fn ($o) => $o->created_at->diffInMinutes($o->shipped_at, false)) / 60, 1);
                $returnRate = $returned / $finished;
                $missingRate = $missing / $finished;
                $proven = $finished >= self::PROVEN;

                // Up to 40 off for returns, 30 for "not received", 30 for being slow: a day is fine, a week or more is the worst.
                $score = $proven
                    ? 100 - min(40, $returnRate * 200) - min(30, $missingRate * 300) - ($hours === null ? 0 : min(30, max(0, ($hours - 24) / 144 * 30)))
                    : self::UNPROVEN_SCORE;

                $out[(int) $sellerId] = ['score' => (int) round(max(0, $score)), 'proven' => $proven, 'ship_hours' => $hours, 'return_rate' => round($returnRate * 100, 1), 'missing_rate' => round($missingRate * 100, 1), 'finished' => $finished];
            });

        return $out;
    }

    /** One seller's score; a seller with no orders yet is in the middle. */
    public function rankOf(User $seller, ?array $ranks = null): array
    {
        return ($ranks ?? $this->ranks())[$seller->id] ?? ['score' => self::UNPROVEN_SCORE, 'proven' => false, 'ship_hours' => null, 'return_rate' => 0.0, 'missing_rate' => 0.0, 'finished' => 0];
    }

    public static function label(int $score, bool $proven): string
    {
        return ! $proven ? 'New' : ($score >= 85 ? 'Excellent' : ($score >= 65 ? 'Good' : 'Needs care'));
    }
}
