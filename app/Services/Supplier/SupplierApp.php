<?php

namespace App\Services\Supplier;

use App\Enums\AccountType;
use App\Models\FoodOrder;
use App\Models\MarketOrder;
use App\Models\Restaurant;
use App\Models\User;

/**
 * The supplier app (spec ch 3): the same website, installed a second time on the phone, for people who sell. One account and one login serve both apps: a button
 * in the customer app opens this one and a button here opens the customer app. It shows only the screens of the account's own kind of business, with what is
 * waiting for them first, so nobody sees another role's screens (spec ch 3: not everyone sees the same screens in the same app).
 */
class SupplierApp
{
    /** Account types that sell, and so have a supplier app. */
    public const TYPES = [AccountType::Wholesaler, AccountType::FoodVendor, AccountType::Mpo, AccountType::Craftsman];

    public function isSupplier(?User $user): bool
    {
        return $user !== null && in_array($user->account_type, self::TYPES, true) && ! $user->isSuspended();
    }

    /**
     * What is waiting, and the screens of this person's business.
     *
     * @return array{waiting: int, waiting_label: string, entries: list<array{title: string, url: string, badge: int}>}
     */
    public function home(User $user): array
    {
        return match ($user->account_type) {
            AccountType::Wholesaler => $this->wholesaler($user),
            AccountType::FoodVendor => $this->food($user),
            AccountType::Mpo => ['waiting' => 0, 'waiting_label' => 'Your MPO screen', 'entries' => [['title' => 'Offers, bids and orders', 'url' => '/mpo', 'badge' => 0]]],
            AccountType::Craftsman => ['waiting' => 0, 'waiting_label' => 'Your jobs', 'entries' => [['title' => 'Your jobs', 'url' => '/craftsman', 'badge' => 0]]],
            default => ['waiting' => 0, 'waiting_label' => '', 'entries' => []],
        };
    }

    /** @return array{waiting: int, waiting_label: string, entries: list<array{title: string, url: string, badge: int}>} */
    private function wholesaler(User $user): array
    {
        $new = MarketOrder::where('seller_id', $user->id)->where('status', 'placed')->where(fn ($q) => $q->where('pay_by', 'cod')->orWhereNotNull('paid_at'))->count();

        return [
            'waiting' => $new, 'waiting_label' => 'new orders waiting for you',
            'entries' => [
                ['title' => 'Orders for your products', 'url' => '/wholesale/sell/orders', 'badge' => $new],
                ['title' => 'Your products', 'url' => '/wholesale/sell', 'badge' => 0],
                ['title' => 'Sales and bundles', 'url' => '/wholesale/sell/deals', 'badge' => 0],
                ['title' => 'Services', 'url' => '/wholesale/sell/services', 'badge' => 0],
                ['title' => 'Price offers', 'url' => '/wholesale/sell/offers', 'badge' => 0],
                ['title' => 'Questions from buyers', 'url' => '/wholesale/sell/questions', 'badge' => 0],
                ['title' => 'How you are doing', 'url' => '/wholesale/sell/reports', 'badge' => 0],
                ['title' => 'Commission statements', 'url' => '/wholesale/sell/statements', 'badge' => 0],
                ['title' => 'Your account', 'url' => '/wholesale/account', 'badge' => 0],
            ],
        ];
    }

    /** @return array{waiting: int, waiting_label: string, entries: list<array{title: string, url: string, badge: int}>} */
    private function food(User $user): array
    {
        $restaurant = Restaurant::where('owner_id', $user->id)->first();
        $new = $restaurant ? FoodOrder::where('restaurant_id', $restaurant->id)->where('status', 'pending')->count() : 0;

        return [
            'waiting' => $new, 'waiting_label' => 'new orders waiting for you',
            'entries' => [
                ['title' => 'Orders, menu and settings', 'url' => '/food/vendor', 'badge' => $new],
                ['title' => 'Build your own: ingredients', 'url' => '/food/vendor/custom', 'badge' => 0],
            ],
        ];
    }
}
