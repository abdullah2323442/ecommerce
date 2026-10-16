<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;

/**
 * What a bulk buyer may order and how they pay (spec ch 14): a minimum quantity per line, and cash on delivery
 * and/or advance, set separately for each customer. Money never passes through the platform; this only records
 * the way this buyer agreed to pay.
 */
class TradeTerms
{
    /** The minimum for one line, in boxes (or pieces for loose items). This customer's own number wins over the shop-wide one. */
    public function moq(User $user): float
    {
        return $user->account_type === AccountType::Wholesaler
            ? max(0.0, (float) ($user->moq ?? Setting::read('wholesale.moq', 0)))
            : 0.0;
    }

    /**
     * Lines under the minimum, with how much each is short.
     *
     * @return list<array{name: string, quantity: float, minimum: float}>
     */
    public function shortLines(Invoice $cart, User $user): array
    {
        $min = $this->moq($user);
        if ($min <= 0) {
            return [];
        }

        return $cart->lines()->with('medicine:id,brand_name,strength')->get()
            ->filter(fn ($l) => ! $l->is_outside && (float) $l->stock_units < $min)
            ->map(fn ($l) => ['name' => trim($l->medicine->brand_name.' '.$l->medicine->strength), 'quantity' => (float) $l->stock_units, 'minimum' => $min])
            ->values()->all();
    }

    /** @return list<string> the ways this buyer may pay: cod, advance */
    public function payments(User $user): array
    {
        if ($user->account_type !== AccountType::Wholesaler) {
            return [];
        }

        return array_values(array_filter([$user->pay_cod ? 'cod' : null, $user->pay_advance ? 'advance' : null]));
    }
}
