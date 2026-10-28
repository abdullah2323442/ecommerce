<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\FoodBase;
use App\Models\FoodItem;
use App\Models\FoodOrder;
use App\Models\Ingredient;
use App\Models\IngredientRequest;
use App\Models\IngredientRule;
use App\Models\Restaurant;
use App\Models\RestaurantIngredient;
use App\Services\Food\CustomFood;
use App\Services\Food\FoodOrders;
use App\Services\Food\Restaurants;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Food\RoadDistance;
use App\Services\Location\LocationException;
use App\Services\Location\Locator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A restaurant owner's own screen (spec ch 17): new orders and their steps, the menu, the hours, the delivery, the sales and what is left
 * after our commission. Only the restaurant's own orders; never another restaurant's.
 */
class FoodVendorController extends Controller
{
    public function __construct(private Restaurants $restaurants, private FoodOrders $orders, private CustomFood $custom) {}

    public function index(Request $request): Response
    {
        $r = $this->mine($request);
        $row = fn (FoodOrder $o) => $this->orderRow($o);
        $open = $r->orders()->whereIn('status', FoodOrder::OPEN)->with('lines')->oldest('id')->get();
        $delivery = $open->filter(fn (FoodOrder $o) => in_array($o->status, ['packing', 'out'], true) && $o->mode === 'delivery');

        return Inertia::render('food/vendor/index', [
            'restaurant' => $this->settings($r), 'openNow' => $this->restaurants->isOpenNow($r),
            // Whether real road distance (Google Maps) is on, and whether an address can be turned into a place.
            'roadByGoogle' => app(RoadDistance::class)->viaGoogle(), 'mapOn' => app(Locator::class)->enabled(),
            'orders' => $open->map($row)->values(),
            // Two orders to nearby houses show together, so one rider can take both and share the charge (spec ch 17).
            'together' => $delivery->groupBy(fn (FoodOrder $o) => mb_strtolower(trim((string) ($o->ship_to['area'] ?? ''))))->filter(fn ($g) => $g->count() > 1)->map(fn ($g, $area) => ['area' => $area, 'numbers' => $g->map->number()->values()])->values(),
            'recent' => $r->orders()->whereIn('status', ['delivered', 'cancelled'])->with('lines')->latest('id')->limit(15)->get()->map($row)->values(),
            'sales' => $this->restaurants->sales($r), 'items' => $r->items->map(fn (FoodItem $i) => $this->itemRow($i))->values(), 'days' => [0, 1, 2, 3, 4, 5, 6],
        ]);
    }

    // ------------------------------------------------------------------ custom food

    /** The ingredient list to tick from, the bases, the rules and the limits (spec ch 17). The list is ours; the prices are theirs. */
    public function custom(Request $request): Response
    {
        $r = $this->mine($request);
        $have = RestaurantIngredient::where('restaurant_id', $r->id)->get()->keyBy('ingredient_id');
        $names = Ingredient::pluck('name', 'id');

        return Inertia::render('food/vendor/custom', [
            'restaurant' => ['name' => $r->name, 'max_ingredients' => $r->max_ingredients, 'custom_extra_minutes' => $r->custom_extra_minutes],
            'groups' => Ingredient::GROUPS, 'kinds' => FoodBase::KINDS,
            'catalog' => Ingredient::where('is_active', true)->orderBy('group')->orderBy('name')->get()->map(function (Ingredient $i) use ($have) {
                $ri = $have->get($i->id);

                return ['id' => $i->id, 'name' => $i->name, 'name_bn' => $i->name_bn, 'group' => $i->group, 'ticked' => $ri !== null, 'ri_id' => $ri?->id, 'price' => $ri ? (float) $ri->price : null,
                    'available' => $ri?->available ?? true, 'sold_out_today' => $ri ? ($ri->available && ! $ri->isOrderable()) : false];
            })->values(),
            'bases' => FoodBase::where('restaurant_id', $r->id)->orderBy('kind')->get()->map(fn (FoodBase $b) => ['id' => $b->id, 'kind' => $b->kind, 'name' => $b->name, 'price' => (float) $b->price, 'cook_minutes' => $b->cook_minutes, 'available' => $b->available, 'sold_out_today' => $b->available && ! $b->isOrderable()])->values(),
            'rules' => IngredientRule::where('restaurant_id', $r->id)->get()->map(fn ($x) => ['id' => $x->id, 'a' => $names[$x->ingredient_a_id] ?? '', 'b' => $names[$x->ingredient_b_id] ?? ''])->values(),
            'ticked' => $have->map(fn ($ri) => ['id' => $ri->ingredient_id, 'name' => $names[$ri->ingredient_id] ?? ''])->values(),
            'requests' => IngredientRequest::where('restaurant_id', $r->id)->latest('id')->limit(15)->get(['id', 'name', 'status', 'note']),
        ]);
    }

    public function tick(Request $request): RedirectResponse
    {
        $r = $this->mine($request);
        $data = $request->validate(['ingredient_id' => ['required', 'integer', 'exists:ingredients,id'], 'price' => ['required', 'numeric', 'min:0', 'max:5000']]);

        return $this->attempt(fn () => $this->custom->tick($r, Ingredient::findOrFail($data['ingredient_id']), (float) $data['price']), 'Saved.');
    }

    public function untick(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $this->custom->untick($this->mine($request), $ingredient);

        return back()->with('status', __('Removed.'));
    }

    public function ingredientAvailable(Request $request, RestaurantIngredient $ri): RedirectResponse
    {
        $this->ownIngredient($request, $ri);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->custom->setAvailable($ri, (bool) $data['on']);

        return back();
    }

    public function ingredientSoldOut(Request $request, RestaurantIngredient $ri): RedirectResponse
    {
        $this->ownIngredient($request, $ri);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->custom->soldOutToday($ri, (bool) $data['on']);

        return back()->with('status', $data['on'] ? __('Sold out for today. It comes back tomorrow.') : __('Back on the menu.'));
    }

    public function saveBase(Request $request, ?FoodBase $base = null): RedirectResponse
    {
        $r = $this->mine($request);
        $data = $request->validate(['kind' => ['required', 'in:burger,pizza,sandwich,roll'], 'name' => ['nullable', 'string', 'max:80'], 'price' => ['required', 'numeric', 'gt:0', 'max:100000'], 'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:240']]);
        if ($base && $base->restaurant_id !== $r->id) {
            throw new AuthorizationException;
        }

        return $this->attempt(fn () => $this->custom->saveBase($r, $data, $base), 'Saved.');
    }

    public function baseAvailable(Request $request, FoodBase $base): RedirectResponse
    {
        $this->ownBase($request, $base);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->custom->setBaseAvailable($base, (bool) $data['on']);

        return back();
    }

    public function baseSoldOut(Request $request, FoodBase $base): RedirectResponse
    {
        $this->ownBase($request, $base);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->custom->soldOutBase($base, (bool) $data['on']);

        return back();
    }

    public function removeBase(Request $request, FoodBase $base): RedirectResponse
    {
        $this->ownBase($request, $base);
        $base->delete();

        return back()->with('status', __('Removed.'));
    }

    public function addRule(Request $request): RedirectResponse
    {
        $r = $this->mine($request);
        $data = $request->validate(['a' => ['required', 'integer', 'exists:ingredients,id'], 'b' => ['required', 'integer', 'exists:ingredients,id']]);

        return $this->attempt(fn () => $this->custom->addRule($r, Ingredient::findOrFail($data['a']), Ingredient::findOrFail($data['b'])), 'Saved.');
    }

    public function removeRule(Request $request, IngredientRule $rule): RedirectResponse
    {
        $this->ownRule($request, $rule);
        $rule->delete();

        return back()->with('status', __('Removed.'));
    }

    public function limits(Request $request): RedirectResponse
    {
        $r = $this->mine($request);
        $data = $request->validate(['max' => ['required', 'integer', 'min:1', 'max:30'], 'extra' => ['required', 'integer', 'min:0', 'max:120']]);

        return $this->attempt(fn () => $this->custom->setLimits($r, (int) $data['max'], (int) $data['extra']), 'Saved.');
    }

    /** An ingredient that is not in the list: the restaurant asks, we read it, and once it is added everyone gets it. */
    public function requestIngredient(Request $request): RedirectResponse
    {
        $r = $this->mine($request);
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'group' => ['nullable', 'in:meat,cheese,vegetables,sauces,extras,spices'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->attempt(fn () => $this->custom->request($r, $data['name'], $data['group'] ?? null, $data['note'] ?? null), 'Sent. We will read it and add it to the list.');
    }

    private function ownBase(Request $request, FoodBase $base): void
    {
        if ($base->restaurant_id !== $this->mine($request)->id) {
            throw new AuthorizationException;
        }
    }

    private function ownRule(Request $request, IngredientRule $rule): void
    {
        if ($rule->restaurant_id !== $this->mine($request)->id) {
            throw new AuthorizationException;
        }
    }

    private function ownIngredient(Request $request, RestaurantIngredient $ri): void
    {
        if ($ri->restaurant_id !== $this->mine($request)->id) {
            throw new AuthorizationException;
        }
    }

    public function setOpen(Request $request): RedirectResponse
    {
        $data = $request->validate(['open' => ['required', 'boolean']]);
        $this->restaurants->setOpen($this->mine($request), (bool) $data['open']);

        return back()->with('status', $data['open'] ? __('You are open.') : __('You are closed.'));
    }

    /** Where an address typed in words is on the map, for the owner setting where the restaurant is. Nothing is saved here. */
    public function locate(Request $request, Locator $locator): JsonResponse
    {
        $this->mine($request);
        $data = $request->validate(['address' => ['required', 'string', 'max:200']]);

        try {
            return response()->json($locator->forward($data['address']));
        } catch (LocationException $e) {
            return response()->json(['message' => __($e->getMessage())], 422);
        }
    }

    public function update(Request $request): RedirectResponse
    {
        $r = $this->mine($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'], 'description' => ['nullable', 'string', 'max:300'], 'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:250'],
            'opens_at' => ['sometimes', 'string'], 'closes_at' => ['sometimes', 'string'], 'closed_days' => ['nullable', 'array'], 'delivery_by' => ['sometimes', 'in:own,platform'],
            'charge_mode' => ['sometimes', 'in:area,distance,manual'], 'per_km' => ['sometimes', 'numeric', 'min:0', 'max:1000'], 'max_km' => ['nullable', 'numeric', 'gt:0', 'max:200'], 'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'], 'charge_inside' => ['sometimes', 'numeric', 'min:0', 'max:5000'], 'charge_outside' => ['sometimes', 'numeric', 'min:0', 'max:5000'], 'min_charge' => ['sometimes', 'numeric', 'min:0', 'max:5000'],
            'eat_there' => ['sometimes', 'boolean'], 'advance_over' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'advance_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'areas' => ['nullable', 'array', 'max:40'], 'areas.*' => ['string', 'max:80'], 'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        return $this->attempt(fn () => $this->restaurants->update($r, $data, $request->file('photo')), 'Saved.');
    }

    // ------------------------------------------------------------------ the menu

    public function storeItem(Request $request): RedirectResponse
    {
        $r = $this->mine($request);

        return $this->attempt(fn () => $this->restaurants->saveItem($r, $this->itemData($request), $request->file('photo')), 'Saved.');
    }

    public function updateItem(Request $request, FoodItem $item): RedirectResponse
    {
        $r = $this->mine($request);
        $this->own($r, $item);

        return $this->attempt(fn () => $this->restaurants->saveItem($r, $this->itemData($request), $request->file('photo'), $item), 'Saved.');
    }

    public function available(Request $request, FoodItem $item): RedirectResponse
    {
        $this->own($this->mine($request), $item);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->restaurants->setAvailable($item, (bool) $data['on']);

        return back();
    }

    public function soldOut(Request $request, FoodItem $item): RedirectResponse
    {
        $this->own($this->mine($request), $item);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->restaurants->soldOutToday($item, (bool) $data['on']);

        return back()->with('status', $data['on'] ? __('Sold out for today. It comes back tomorrow.') : __('Back on the menu.'));
    }

    public function destroyItem(Request $request, FoodItem $item): RedirectResponse
    {
        $this->own($this->mine($request), $item);
        $item->delete();

        return back()->with('status', __('Removed.'));
    }

    // ------------------------------------------------------------------ orders

    public function confirm(Request $request, FoodOrder $order): RedirectResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:240'], 'charge' => ['nullable', 'numeric', 'min:0', 'max:5000']]);

        return $this->attempt(fn () => $this->orders->confirm($this->theirs($request, $order), $request->user(), (int) $data['minutes'], isset($data['charge']) ? (float) $data['charge'] : null), 'Confirmed. The buyer sees a timer.');
    }

    public function advance(Request $request, FoodOrder $order): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:1000000']]);

        return $this->attempt(fn () => $this->orders->receiveAdvance($this->theirs($request, $order), $request->user(), (float) $data['amount']), 'Noted.');
    }

    /** The plain steps: packing, out for delivery, ready, delivered. */
    public function step(Request $request, FoodOrder $order, string $step): RedirectResponse
    {
        abort_unless(in_array($step, ['packing', 'out', 'ready', 'deliver'], true), 404);
        $o = $this->theirs($request, $order);

        return $this->attempt(fn () => match ($step) {
            'packing' => $this->orders->packing($o, $request->user()),
            'out' => $this->orders->out($o, $request->user()),
            'ready' => $this->orders->ready($o, $request->user()),
            'deliver' => $this->orders->deliver($o, $request->user()),
        }, 'Done.');
    }

    public function cancel(Request $request, FoodOrder $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        return $this->attempt(fn () => $this->orders->cancel($this->theirs($request, $order), 'owner', $data['reason'], $request->user()), 'Cancelled.');
    }

    public function reply(Request $request, FoodOrder $order): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->attempt(fn () => $this->orders->reply($this->theirs($request, $order), $request->user(), $data['text']), 'Sent.');
    }

    /** The memo, in the restaurant's name with ours small at the bottom; or the kitchen ticket with the details large (spec ch 17). */
    public function print(Request $request, FoodOrder $order, string $kind): Response
    {
        abort_unless(in_array($kind, ['memo', 'kitchen'], true), 404);
        $o = $this->theirs($request, $order)->load(['lines', 'restaurant']);

        return Inertia::render('food/vendor/print', ['kind' => $kind, 'order' => $this->orderRow($o) + ['subtotal' => (float) $o->subtotal, 'delivery_charge' => (float) $o->delivery_charge, 'total' => (float) $o->total], 'restaurant' => ['name' => $o->restaurant->name, 'phone' => $o->restaurant->phone, 'address' => $o->restaurant->address], 'brand' => (string) config('brand.name')]);
    }

    public function complaintPhoto(Request $request, FoodOrder $order): StreamedResponse
    {
        $o = $this->theirs($request, $order);
        abort_unless($o->complaint_photo !== null && Storage::exists($o->complaint_photo), 404);

        return Storage::response($o->complaint_photo, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    // ------------------------------------------------------------------ helpers

    private function orderRow(FoodOrder $o): array
    {
        $o->loadMissing('lines');

        return [
            'id' => $o->id, 'number' => $o->number(), 'status' => $o->status, 'mode' => $o->mode, 'ship_to' => $o->ship_to, 'eat_time' => $o->eat_time, 'note' => $o->note,
            'lines' => $o->lines->map(fn ($l) => ['name' => $l->name, 'price' => (float) $l->price, 'quantity' => $l->quantity, 'note' => $l->note, 'ingredients' => $l->ingredients])->values(), 'suggest_minutes' => $this->orders->suggestMinutes($o), 'total' => (float) $o->total, 'subtotal' => (float) $o->subtotal,
            'delivery_charge' => (float) $o->delivery_charge, 'charge_open' => $o->charge_open, 'advance_due' => (float) $o->advance_due, 'advance_paid' => (float) $o->advance_paid, 'minutes' => $o->cook_minutes,
            'ready_at' => $o->ready_at?->toIso8601String(), 'placed_at' => $o->created_at->toIso8601String(), 'commission' => (float) $o->commission, 'complaint' => $o->complaint, 'has_complaint_photo' => $o->complaint_photo !== null,
            'complaint_reply' => $o->complaint_reply, 'cancel_reason' => $o->cancel_reason, 'cooking' => $o->cookingStarted(),
        ];
    }

    private function itemRow(FoodItem $i): array
    {
        return [
            'id' => $i->id, 'name' => $i->name, 'description' => $i->description, 'category' => $i->category, 'price' => (float) $i->price, 'cook_minutes' => $i->cook_minutes, 'ready_made' => $i->ready_made, 'available' => $i->available,
            'sold_out_today' => ! $i->isOrderable() && $i->available, 'has_photo' => $i->photo_path !== null,
        ];
    }

    private function settings(Restaurant $r): array
    {
        return [
            'name' => $r->name, 'description' => $r->description, 'phone' => $r->phone, 'address' => $r->address, 'area' => $r->area, 'areas' => $r->areas, 'is_open' => $r->is_open, 'opens_at' => $r->opens_at, 'closes_at' => $r->closes_at,
            'closed_days' => $r->closed_days ?? [], 'delivery_by' => $r->delivery_by, 'charge_mode' => $r->charge_mode, 'charge_inside' => (float) $r->charge_inside, 'charge_outside' => (float) $r->charge_outside,
            'min_charge' => (float) $r->min_charge, 'per_km' => (float) $r->per_km, 'max_km' => $r->max_km !== null ? (float) $r->max_km : null, 'lat' => $r->lat, 'lng' => $r->lng, 'eat_there' => $r->eat_there, 'advance_over' => $r->advance_over !== null ? (float) $r->advance_over : null, 'advance_percent' => $r->advance_percent, 'status' => $r->status,
            // What we take is theirs to know: they see their commission, and what they will receive after it.
            'commission_kind' => $r->commission_kind, 'commission_value' => (float) $r->commission_value,
        ];
    }

    private function itemData(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'min:2', 'max:120'], 'description' => ['nullable', 'string', 'max:300'], 'category' => ['nullable', 'string', 'max:40'], 'price' => ['required', 'numeric', 'gt:0', 'max:100000'], 'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:240'], 'ready_made' => ['nullable', 'boolean'], 'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['vendor' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    /** The restaurant of this signed-in owner; anyone else is turned away. */
    private function mine(Request $request): Restaurant
    {
        $user = $request->user();

        if ($user?->account_type !== AccountType::FoodVendor) {
            throw new AuthorizationException;
        }

        return Restaurant::where('owner_id', $user->id)->first() ?? throw new AuthorizationException;
    }

    private function theirs(Request $request, FoodOrder $order): FoodOrder
    {
        if ($order->restaurant_id !== $this->mine($request)->id) {
            throw new AuthorizationException;
        }

        return $order;
    }

    private function own(Restaurant $r, FoodItem $item): void
    {
        if ($item->restaurant_id !== $r->id) {
            throw new AuthorizationException;
        }
    }
}
