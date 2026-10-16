<?php

namespace App\Services\Food;

use App\Models\FoodItem;
use App\Models\FoodOrder;
use App\Models\Notice;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\ImageCompressor;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Food orders (spec ch 17). The buyer orders, the owner confirms and says how long it will take, a timer runs on the buyer's screen, then
 * packing, out for delivery (or ready, for "I'll eat there"), delivered. Commission counts on complete sales only; cancelled means none, but once
 * cooking has started an advance is not given back.
 */
class FoodOrders
{
    public function __construct(private Restaurants $restaurants, private Messenger $messages, private ImageCompressor $images, private CustomFood $custom) {}

    public function confirmMinutes(): int
    {
        return max(1, (int) Setting::read('food.confirm_minutes', 10));
    }

    // ------------------------------------------------------------------ ordering

    /**
     * @param  array<int, array{item_id: int, quantity: int, note?: ?string}>  $lines
     * @param  array{name: string, phone: string, area?: string, address?: string}  $ship
     *
     * @throws StockException
     */
    public function place(Restaurant $restaurant, ?User $buyer, array $lines, string $mode, array $ship, ?string $eatTime = null, ?string $note = null): FoodOrder
    {
        if (! $this->restaurants->isOpenNow($restaurant)) {
            throw new StockException('This restaurant is closed now.');
        }

        if (! in_array($mode, ['delivery', 'eat_there'], true) || ($mode === 'eat_there' && ! $restaurant->eat_there)) {
            throw new StockException('Choose home delivery or eating there.');
        }

        if ($mode === 'delivery') {
            if (trim((string) ($ship['area'] ?? '')) === '' || trim((string) ($ship['address'] ?? '')) === '') {
                throw new StockException('Write your area and address.');
            }

            if (! $this->restaurants->serves($restaurant, (string) $ship['area'])) {
                throw new StockException('This restaurant does not deliver to your area.');
            }
        }

        if ($lines === []) {
            throw new StockException('Add something to your order.');
        }

        $items = FoodItem::where('restaurant_id', $restaurant->id)->whereIn('id', collect($lines)->pluck('item_id')->filter())->get()->keyBy('id');
        $rows = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $qty = (int) ($line['quantity'] ?? 0);

            // A dish the buyer built: the base, the ingredients, the limit and the rules are all checked here, never trusted from the screen.
            if (! empty($line['base_id'])) {
                if ($qty < 1 || $qty > 50) {
                    throw new StockException('Choose one to fifty of each dish.');
                }

                $built = $this->custom->build($restaurant, (int) $line['base_id'], (array) ($line['ingredient_ids'] ?? []));
                $rows[] = [
                    'food_item_id' => null, 'name' => $built['base']->name, 'price' => $built['unit_price'], 'quantity' => $qty, 'note' => ! empty($line['note']) ? mb_substr(trim((string) $line['note']), 0, 120) : null,
                    'ingredients' => ['base' => ['name' => $built['base']->name, 'price' => (float) $built['base']->price, 'cook' => $built['minutes']], 'items' => $built['picked']->map(fn ($p) => ['name' => $p['name'], 'price' => $p['price']])->all()],
                ];
                $subtotal += $built['unit_price'] * $qty;

                continue;
            }

            $item = $items->get((int) ($line['item_id'] ?? 0));

            if (! $item || ! $item->isOrderable()) {
                throw new StockException(__(':name is not available now.', ['name' => $item?->name ?? __('This dish')]));
            }

            if ($qty < 1 || $qty > 50) {
                throw new StockException('Choose one to fifty of each dish.');
            }

            $rows[] = ['food_item_id' => $item->id, 'name' => $item->name, 'price' => (float) $item->price, 'quantity' => $qty, 'note' => ! empty($line['note']) ? mb_substr(trim((string) $line['note']), 0, 120) : null];
            $subtotal += (float) $item->price * $qty;
        }

        [$charge, $open] = $mode === 'delivery' ? $this->charge($restaurant, (string) $ship['area']) : [0.0, false];
        $total = round($subtotal + $charge, 2);
        $advance = $restaurant->advance_over !== null && $restaurant->advance_percent > 0 && $total >= (float) $restaurant->advance_over ? round($total * $restaurant->advance_percent / 100, 2) : 0.0;

        $order = DB::transaction(function () use ($restaurant, $buyer, $rows, $mode, $ship, $eatTime, $note, $subtotal, $charge, $open, $total, $advance) {
            $order = FoodOrder::create([
                'public_token' => Str::random(40), 'restaurant_id' => $restaurant->id, 'buyer_id' => $buyer?->id, 'mode' => $mode, 'status' => 'pending',
                'ship_to' => collect($ship)->only(['name', 'phone', 'area', 'address', 'how_to_find'])->all(), 'eat_time' => $mode === 'eat_there' && $eatTime ? mb_substr(trim($eatTime), 0, 30) : null,
                'note' => $note ? mb_substr(trim($note), 0, 300) : null, 'subtotal' => round($subtotal, 2), 'delivery_charge' => $charge, 'charge_open' => $open, 'total' => $total, 'advance_due' => $advance,
            ]);
            $order->lines()->createMany($rows);

            return $order;
        });

        $this->messages->send($restaurant->owner, 'food_order_new', ['name' => $order->number()], '/food/vendor');

        return $order;
    }

    /**
     * The delivery charge for an address, and whether the owner types it in when confirming. "Area-based": one price inside the restaurant's own
     * area, another outside; never less than the minimum. Charging by road distance needs a map service, which is not connected yet.
     *
     * @return array{0: float, 1: bool}
     */
    public function charge(Restaurant $r, string $area): array
    {
        if ($r->charge_mode === 'manual') {
            return [0.0, true];
        }

        $inside = mb_strtolower(trim($area)) === mb_strtolower(trim($r->area));

        return [max((float) $r->min_charge, (float) ($inside ? $r->charge_inside : $r->charge_outside)), false];
    }

    // ------------------------------------------------------------------ the restaurant's steps

    /**
     * Confirm, and say how long it will take (spec ch 17). An order that needs an advance waits until the advance is in.
     *
     * @throws StockException
     */
    public function confirm(FoodOrder $order, User $owner, int $minutes, ?float $charge = null): FoodOrder
    {
        $this->mustBeTheirs($order, $owner);

        if ($order->status !== 'pending') {
            throw new StockException('This order was already answered.');
        }

        if ($minutes < 1 || $minutes > 240) {
            throw new StockException('Say how many minutes it will take.');
        }

        if ($order->charge_open && ($charge === null || $charge < 0)) {
            throw new StockException('Write the delivery charge.');
        }

        if ((float) $order->advance_due > (float) $order->advance_paid + 0.001) {
            throw new StockException('This order needs an advance. Confirm after it is received.');
        }

        $changes = ['status' => 'confirmed', 'cook_minutes' => $minutes, 'confirmed_at' => now(), 'ready_at' => now()->addMinutes($minutes)];

        if ($order->charge_open) {
            $changes += ['delivery_charge' => round($charge, 2), 'total' => round((float) $order->subtotal + $charge, 2), 'charge_open' => false];
        }

        $order->update($changes);
        $this->tell($order, 'confirmed', ['minutes' => $minutes]);

        return $order->fresh();
    }

    public function receiveAdvance(FoodOrder $order, User $by, float $amount): FoodOrder
    {
        $this->mustBeTheirs($order, $by);

        if (! $order->isOpen() || $amount <= 0 || (float) $order->advance_paid + $amount > (float) $order->total + 0.001) {
            throw new StockException('Check the amount.');
        }

        $order->update(['advance_paid' => round((float) $order->advance_paid + $amount, 2)]);

        return $order->fresh();
    }

    public function packing(FoodOrder $order, User $owner): FoodOrder
    {
        $this->mustBeTheirs($order, $owner);

        if ($order->status !== 'confirmed' || $order->mode !== 'delivery') {
            throw new StockException('Only a confirmed delivery order can be packed.');
        }

        $order->update(['status' => 'packing', 'packing_at' => now()]);
        $this->tell($order, 'packing');

        return $order->fresh();
    }

    public function out(FoodOrder $order, User $owner): FoodOrder
    {
        $this->mustBeTheirs($order, $owner);

        if (! in_array($order->status, ['confirmed', 'packing'], true) || $order->mode !== 'delivery') {
            throw new StockException('Only a delivery order that is cooked can go out.');
        }

        $order->update(['status' => 'out', 'out_at' => now()]);
        $this->tell($order, 'out');

        return $order->fresh();
    }

    /** "I'll eat there": the table is ready for them. */
    public function ready(FoodOrder $order, User $owner): FoodOrder
    {
        $this->mustBeTheirs($order, $owner);

        if ($order->status !== 'confirmed' || $order->mode !== 'eat_there') {
            throw new StockException('Only a confirmed eat-there order can be made ready.');
        }

        $order->update(['status' => 'ready']);
        $this->tell($order, 'ready');

        return $order->fresh();
    }

    /** Delivered (or served). This is the complete sale: the commission is counted now. */
    public function deliver(FoodOrder $order, User $owner): FoodOrder
    {
        $this->mustBeTheirs($order, $owner);

        if (! in_array($order->status, ['out', 'ready'], true)) {
            throw new StockException('Only an order that is out or ready can be marked delivered.');
        }

        $order->update(['status' => 'delivered', 'delivered_at' => now(), 'commission' => $this->restaurants->commissionOn($order->restaurant, (float) $order->subtotal)]);
        $this->tell($order, 'delivered');

        return $order->fresh();
    }

    // ------------------------------------------------------------------ cancelling

    /**
     * Before the restaurant confirms the advance goes back. Once cooking has started it does not (the food is made), unless the restaurant
     * itself cancels. Nothing can be cancelled once it is out for delivery.
     *
     * @param  'buyer'|'owner'|'staff'  $by
     */
    public function cancel(FoodOrder $order, string $by, ?string $reason = null, ?User $actor = null): FoodOrder
    {
        if (! in_array($order->status, ['pending', 'confirmed', 'packing'], true)) {
            throw new StockException('It is too late to cancel this order.');
        }

        if ($by === 'owner' && $actor) {
            $this->mustBeTheirs($order, $actor);
        }

        $refund = $by === 'buyer' && $order->cookingStarted() ? 0.0 : (float) $order->advance_paid;

        $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by, 'cancel_reason' => $reason ? mb_substr(trim($reason), 0, 200) : null, 'refund_amount' => $refund, 'commission' => 0]);

        if ($by === 'buyer') {
            $this->messages->send($order->restaurant->owner, 'food_cancelled', ['name' => $order->number()], '/food/vendor');
        } else {
            $this->tell($order, 'cancelled');
        }

        return $order->fresh();
    }

    /** A restaurant that never confirms: the buyer is asked whether to cancel, once. Returns how many were asked. */
    public function nudgeUnconfirmed(): int
    {
        $n = 0;

        FoodOrder::where('status', 'pending')->whereNull('nudged_at')->where('created_at', '<=', now()->subMinutes($this->confirmMinutes()))->with('buyer')->each(function (FoodOrder $o) use (&$n) {
            $o->update(['nudged_at' => now()]);
            if ($o->buyer) {
                $this->messages->send($o->buyer, 'food_unconfirmed', ['name' => $o->number()], '/food/orders/'.$o->public_token);
            }
            $n++;
        });

        return $n;
    }

    /** The buyer's page asks the question itself when the time has passed (a guest has no inbox). */
    public function unconfirmedTooLong(FoodOrder $order): bool
    {
        return $order->status === 'pending' && $order->created_at->lte(now()->subMinutes($this->confirmMinutes()));
    }

    // ------------------------------------------------------------------ complaints

    /** "The food arrived spoiled": a complaint with a photo goes to the restaurant and to us. */
    public function complain(FoodOrder $order, string $text, ?UploadedFile $photo): FoodOrder
    {
        if ($order->status !== 'delivered') {
            throw new StockException('You can complain once the order is delivered.');
        }

        if ($order->complaint !== null) {
            throw new StockException('We already have your complaint.');
        }

        if (mb_strlen(trim($text)) < 3) {
            throw new StockException('Write what is wrong.');
        }

        if (! $photo) {
            throw new StockException('A photo is needed with the complaint.');
        }

        $order->update(['complaint' => mb_substr(trim($text), 0, 500), 'complaint_photo' => $this->images->store($photo, 'food-complaints')]);
        $this->messages->send($order->restaurant->owner, 'food_complaint', ['name' => $order->number()], '/food/vendor');

        foreach (User::withPermission('customers') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'food_complaint', 'body' => mb_substr($order->restaurant->name.': '.$order->number().' has a complaint with a photo', 0, 290)]);
        }

        return $order->fresh();
    }

    public function reply(FoodOrder $order, User $owner, string $text): FoodOrder
    {
        $this->mustBeTheirs($order, $owner);

        if ($order->complaint === null || mb_strlen(trim($text)) < 3) {
            throw new StockException('There is nothing to answer.');
        }

        $order->update(['complaint_reply' => mb_substr(trim($text), 0, 300)]);

        return $order->fresh();
    }

    // ------------------------------------------------------------------ what each side sees

    /** The buyer's page: the steps, the timer, never anything about the restaurant's money. */
    public function forBuyer(FoodOrder $o): array
    {
        $o->loadMissing(['lines', 'restaurant']);
        $seconds = $o->status === 'confirmed' && $o->ready_at ? max(0, (int) now()->diffInSeconds($o->ready_at, false)) : null;

        return [
            'token' => $o->public_token, 'number' => $o->number(), 'status' => $o->status, 'mode' => $o->mode, 'restaurant' => $o->restaurant->name, 'restaurant_phone' => $o->restaurant->phone,
            'lines' => $o->lines->map(fn ($l) => ['name' => $l->name, 'price' => (float) $l->price, 'quantity' => $l->quantity, 'note' => $l->note, 'ingredients' => $l->ingredients])->values(), 'custom' => $o->lines->contains(fn ($l) => $l->ingredients !== null),
            'subtotal' => (float) $o->subtotal, 'delivery_charge' => (float) $o->delivery_charge, 'charge_open' => $o->charge_open, 'total' => (float) $o->total,
            'advance_due' => (float) $o->advance_due, 'advance_paid' => (float) $o->advance_paid, 'minutes' => $o->cook_minutes, 'ready_at' => $o->ready_at?->toIso8601String(), 'seconds_left' => $seconds,
            'ship_to' => $o->ship_to, 'eat_time' => $o->eat_time, 'note' => $o->note, 'placed_at' => $o->created_at->toIso8601String(), 'cancel_reason' => $o->cancel_reason, 'refund' => (float) $o->refund_amount,
            'ask_cancel' => $this->unconfirmedTooLong($o), 'can_cancel' => in_array($o->status, ['pending', 'confirmed', 'packing'], true), 'cancel_no_refund' => $o->cookingStarted() && (float) $o->advance_paid > 0,
            'can_complain' => $o->status === 'delivered' && $o->complaint === null, 'complaint' => $o->complaint, 'complaint_reply' => $o->complaint_reply,
        ];
    }

    /** How long to suggest when confirming: the longest dish, and custom food takes longer (spec ch 17: the buyer is told). */
    public function suggestMinutes(FoodOrder $o): int
    {
        $o->loadMissing(['lines', 'restaurant']);
        $items = FoodItem::whereIn('id', $o->lines->pluck('food_item_id')->filter())->pluck('cook_minutes', 'id');

        return (int) max(10, ...$o->lines->map(fn ($l) => $l->ingredients !== null ? (int) ($l->ingredients['base']['cook'] ?? 15) : (int) ($items[$l->food_item_id] ?? 15))->all());
    }

    // ------------------------------------------------------------------ helpers

    private function mustBeTheirs(FoodOrder $order, User $user): void
    {
        $order->loadMissing('restaurant');

        if ($order->restaurant->owner_id !== $user->id) {
            throw new StockException('This is not your order.');
        }
    }

    private function tell(FoodOrder $order, string $what, array $data = []): void
    {
        if ($order->buyer) {
            $this->messages->send($order->buyer, $what === 'cancelled' ? 'food_cancelled' : 'food_update', ['name' => $order->number(), 'what' => $what] + $data, '/food/orders/'.$order->public_token);
        }
    }
}
