<?php

namespace App\Services\Sales;

use App\Models\CustomOrder;
use App\Models\FoodOrder;
use App\Models\GameOrder;
use App\Models\MarketOrder;
use App\Services\Market\Marketplace;

/**
 * A clean receipt for every kind of order (spec ch 22: "a nice memo in every sector, public customers get one too"). Whoever holds the private link of an
 * order may open its receipt. Every step is on its own line so the buyer can check it, and what is nobody's business never appears: no cost, no
 * commission, no craftsman, no wage, and a market seller's name only where the owner shows it.
 */
class Receipts
{
    public const KINDS = ['market', 'food', 'game', 'custom'];

    public function __construct(private Marketplace $market) {}

    /** @return array<string, mixed>|null */
    public function for(string $kind, string $token): ?array
    {
        return match ($kind) {
            'market' => ($o = MarketOrder::with('listing.seller')->where('public_token', $token)->first()) ? $this->market($o) : null,
            'food' => ($o = FoodOrder::with('restaurant', 'lines')->where('public_token', $token)->first()) ? $this->food($o) : null,
            'game' => ($o = GameOrder::where('public_token', $token)->first()) ? $this->game($o) : null,
            'custom' => ($o = CustomOrder::where('public_token', $token)->first()) ? $this->custom($o) : null,
            default => null,
        };
    }

    /** What is the same on every receipt: our name, place and phone. */
    private function business(?string $name = null): array
    {
        return ['name' => $name ?? (string) config('brand.name'), 'address' => config('brand.address'), 'phone' => config('brand.phone')];
    }

    private function market(MarketOrder $o): array
    {
        $ship = (array) $o->ship_to;
        $goods = round((float) $o->quantity * (float) $o->unit_price, 2);
        $name = $o->title.($o->variant_label ? ' ('.$o->variant_label.')' : '');
        $steps = [['label' => __('Goods'), 'amount' => $goods]];

        if ((float) $o->delivery_charge > 0) {
            $steps[] = ['label' => __('Delivery charge'), 'amount' => (float) $o->delivery_charge];
        }

        if ((float) $o->token_amount > 0) {
            $steps[] = ['label' => __('Aqua Token'), 'amount' => -(float) $o->token_amount];
        }

        return [
            'kind' => 'market', 'title' => __('Receipt'), 'number' => $o->number(), 'date' => $o->created_at->toIso8601String(), 'business' => $this->business(),
            // The seller's own name only where the owner shows it (spec ch 05); otherwise the goods are ours.
            'sold_by' => $o->listing ? $this->market->sellerName($o->listing) : (string) config('brand.name'),
            'buyer' => ['name' => $ship['name'] ?? null, 'phone' => $ship['phone'] ?? null, 'address' => $o->pickup ? __('Collected from the shop') : trim(($ship['address'] ?? '').', '.($ship['area'] ?? '').', '.($ship['district'] ?? ''), ', ')],
            'lines' => [['name' => $name, 'qty' => (float) $o->quantity, 'unit' => $o->unit, 'price' => (float) $o->unit_price, 'total' => $goods]],
            'steps' => $steps, 'total' => (float) $o->total,
            'payment' => ['method' => __('Pay on delivery'), 'status' => $o->status === 'delivered' ? __('Paid') : ($o->status === 'cancelled' ? __('Cancelled') : __('To pay'))],
            'notes' => [$o->listing?->return_note ?: ($o->listing?->sector?->return_rule ?: __('You can send it back within :n days of getting it.', ['n' => app(\App\Services\Market\MarketCases::class)->returnDays($o)]))],
        ];
    }

    private function food(FoodOrder $o): array
    {
        $steps = [['label' => __('Food'), 'amount' => (float) $o->subtotal]];

        if ((float) $o->delivery_charge > 0) {
            $steps[] = ['label' => __('Delivery charge'), 'amount' => (float) $o->delivery_charge];
        }

        $ship = (array) $o->ship_to;

        return [
            'kind' => 'food', 'title' => __('Receipt'), 'number' => $o->number(), 'date' => $o->created_at->toIso8601String(),
            // In the restaurant's name, ours small at the bottom (spec ch 22).
            'business' => ['name' => $o->restaurant?->name ?? (string) config('brand.name'), 'address' => $o->restaurant?->address, 'phone' => $o->restaurant?->phone], 'powered_by' => (string) config('brand.name'),
            'buyer' => ['name' => $ship['name'] ?? null, 'phone' => $ship['phone'] ?? null, 'address' => $o->mode === 'delivery' ? trim(($ship['address'] ?? '').', '.($ship['area'] ?? ''), ', ') : __('Eaten at the restaurant')],
            'lines' => $o->lines->map(fn ($i) => ['name' => $i->name, 'qty' => (float) $i->quantity, 'unit' => null, 'price' => (float) $i->price, 'total' => round((float) $i->price * (float) $i->quantity, 2)])->values()->all(),
            'steps' => $steps, 'total' => (float) $o->total,
            'payment' => ['method' => (float) $o->advance_paid > 0 ? __('Part paid ahead') : __('Pay when it comes'), 'status' => $o->status === 'delivered' ? __('Done') : ($o->status === 'cancelled' ? __('Cancelled') : __('In progress'))],
            'notes' => [__('Food cannot be sent back. If something is wrong, tell us the same day.')],
        ];
    }

    private function game(GameOrder $o): array
    {
        return [
            'kind' => 'game', 'title' => __('Top-up receipt'), 'number' => $o->number(), 'date' => $o->created_at->toIso8601String(), 'business' => $this->business(),
            'buyer' => ['name' => $o->player_name, 'phone' => $o->contact_phone, 'address' => null, 'player' => trim($o->player_id.($o->zone ? ' ('.$o->zone.')' : ''))],
            'lines' => [['name' => $o->game_name.' · '.$o->package_name, 'qty' => 1.0, 'unit' => null, 'price' => (float) $o->price, 'total' => (float) $o->price]],
            'steps' => [], 'total' => (float) $o->price,
            'payment' => ['method' => $o->pay_method ? ucfirst($o->pay_method) : '—', 'status' => match ($o->status) {
                'topped_up' => __('Topped up'), 'failed' => __('Failed'), 'cancelled' => __('Cancelled'), 'paid' => __('Top-up in progress'), 'claimed' => __('We check the payment'), default => __('To pay'),
            }],
            'notes' => [__('A top-up cannot be taken back once it is done.')],
        ];
    }

    private function custom(CustomOrder $o): array
    {
        $steps = [['label' => __('Price'), 'amount' => (float) ($o->price ?? 0)]];

        if ((float) $o->advance_paid > 0) {
            $steps[] = ['label' => __('Paid ahead'), 'amount' => -(float) $o->advance_paid];
        }

        return [
            'kind' => 'custom', 'title' => __('Receipt'), 'number' => $o->number(), 'date' => $o->created_at->toIso8601String(), 'business' => $this->business(),
            'buyer' => ['name' => $o->buyer?->name, 'phone' => $o->buyer?->phone, 'address' => null],
            'lines' => [['name' => $o->kind === 'wood' ? __('Wood, made to your measurements') : __('Clothing, made to your measurements'), 'qty' => 1.0, 'unit' => null, 'price' => (float) ($o->price ?? 0), 'total' => (float) ($o->price ?? 0)]],
            'steps' => $steps, 'total' => (float) ($o->price ?? 0),
            'payment' => ['method' => __('Paid ahead, rest on collecting'), 'status' => $o->collected_at ? __('Done') : ($o->cancelled_at ? __('Cancelled') : __('In progress'))],
            'notes' => [__('Returns do not apply to a job made to your own measurements.')],
        ];
    }
}
