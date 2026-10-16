<?php

namespace App\Services\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\DB;

/**
 * A shop's own page (spec ch 13): all its products, how it is rated, how long it has been here, and a Follow button. Only a seller whose own
 * name is switched on has a page; everyone else's products stay under our name (spec ch 05), so no page, no follow, and no hint they exist.
 */
class Shops
{
    public function __construct(private Marketplace $market, private SellerReports $reports, private Messenger $messages) {}

    /** Has a page: a trade seller whose name the owner has switched on and who is not stopped. */
    public function hasPage(User $seller): bool
    {
        return $seller->account_type === AccountType::Wholesaler && $seller->show_shop_name && ! $seller->isSuspended() && ! $seller->sellingStopped();
    }

    /** @return array<string, mixed> */
    public function summary(User $seller, ?User $viewer): array
    {
        $rank = $this->reports->rankOf($seller);
        $months = (int) $seller->created_at->diffInMonths(now());

        return [
            'id' => $seller->id, 'name' => $seller->business_name ?: $seller->name, 'area' => $seller->area,
            'months' => $months, 'since' => $seller->created_at->toDateString(),
            // How it is rated: the rank that already puts better sellers higher; a new shop is just "New".
            'rating' => SellerReports::label($rank['score'], $rank['proven']), 'followers' => $this->followers($seller),
            'following' => $viewer ? DB::table('shop_follows')->where('user_id', $viewer->id)->where('seller_id', $seller->id)->exists() : false,
            'can_follow' => $viewer !== null && $viewer->id !== $seller->id && in_array($viewer->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true),
            'paused' => (bool) $seller->seller_paused,
        ];
    }

    /** The shop's products this viewer can see, priced for them. */
    public function products(User $seller, ?User $viewer)
    {
        return $this->market->visible($viewer)->filter(fn (array $r) => $r['listing']->seller_id === $seller->id)->values();
    }

    public function followers(User $seller): int
    {
        return DB::table('shop_follows')->where('seller_id', $seller->id)->count();
    }

    /** @throws StockException */
    public function follow(User $buyer, User $seller): void
    {
        if (! $this->hasPage($seller)) {
            throw new StockException('This shop cannot be followed.');
        }

        if ($buyer->id === $seller->id) {
            throw new StockException('You cannot follow your own shop.');
        }

        DB::table('shop_follows')->insertOrIgnore(['user_id' => $buyer->id, 'seller_id' => $seller->id, 'created_at' => now()]);
    }

    public function unfollow(User $buyer, User $seller): void
    {
        DB::table('shop_follows')->where('user_id', $buyer->id)->where('seller_id', $seller->id)->delete();
    }

    /** A product of a shop was shown for the first time: its followers are told (once, not on every later edit). */
    public function announce(Listing $listing): int
    {
        $seller = $listing->seller;

        if (! $seller || ! $this->hasPage($seller)) {
            return 0;
        }

        $count = 0;
        User::whereIn('id', DB::table('shop_follows')->where('seller_id', $seller->id)->pluck('user_id'))->get()->each(function (User $follower) use ($listing, $seller, &$count) {
            $this->messages->send($follower, 'shop_new_product', ['name' => $listing->title, 'shop' => $seller->business_name ?: $seller->name], '/market/'.$listing->id);
            $count++;
        });

        return $count;
    }
}
