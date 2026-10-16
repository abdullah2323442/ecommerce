<?php

namespace App\Services\Food;

use App\Models\FoodBase;
use App\Models\FoodFavorite;
use App\Models\FoodOrderItem;
use App\Models\Ingredient;
use App\Models\IngredientRequest;
use App\Models\IngredientRule;
use App\Models\Notice;
use App\Models\Restaurant;
use App\Models\RestaurantIngredient;
use App\Models\User;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;

/**
 * Custom food (spec ch 17): the buyer builds their own on a base (burger, pizza, sandwich, roll) from ingredients. We keep the one list of
 * ingredients; a restaurant only ticks what it has and sets its own price for each, and cannot type a new name. A restaurant can sell an
 * ingredient out for the day, set a limit on how many go in one dish, and say which pairs do not go together.
 */
class CustomFood
{
    public function __construct(private Restaurants $restaurants) {}

    // ------------------------------------------------------------------ the central list (ours)

    /** @throws StockException */
    public function addIngredient(string $name, ?string $nameBn, string $group): Ingredient
    {
        $name = trim($name);

        if (mb_strlen($name) < 2) {
            throw new StockException('Give the ingredient a name.');
        }

        if (! in_array($group, Ingredient::GROUPS, true)) {
            throw new StockException('Pick a group for the ingredient.');
        }

        // Cheese, cheese and CHEESE are one thing: the list has no repeats.
        if (Ingredient::whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new StockException('This ingredient is already in the list.');
        }

        return Ingredient::create(['name' => mb_substr($name, 0, 80), 'name_bn' => $nameBn ? mb_substr(trim($nameBn), 0, 80) : null, 'group' => $group, 'is_active' => true]);
    }

    public function updateIngredient(Ingredient $i, array $data): Ingredient
    {
        if (isset($data['group']) && ! in_array($data['group'], Ingredient::GROUPS, true)) {
            throw new StockException('Pick a group for the ingredient.');
        }

        $i->update(collect($data)->only(['name_bn', 'group', 'is_active'])->all());

        return $i->fresh();
    }

    // ------------------------------------------------------------------ asking us to add one

    public function request(Restaurant $r, string $name, ?string $group, ?string $note = null): IngredientRequest
    {
        $name = trim($name);

        if (mb_strlen($name) < 2) {
            throw new StockException('Write the name of the ingredient.');
        }

        if (Ingredient::whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new StockException('This ingredient is already in the list. Tick it.');
        }

        if (IngredientRequest::where('restaurant_id', $r->id)->where('status', 'pending')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new StockException('You have already asked for this.');
        }

        $request = IngredientRequest::create(['restaurant_id' => $r->id, 'name' => mb_substr($name, 0, 80), 'group' => in_array($group, Ingredient::GROUPS, true) ? $group : null, 'note' => $note ? mb_substr(trim($note), 0, 200) : null, 'status' => 'pending']);

        foreach (User::withPermission('customers') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'ingredient_request', 'body' => mb_substr($r->name.' asks for "'.$name.'"', 0, 290)]);
        }

        return $request;
    }

    /** We read it and add it (then everyone gets it) or turn it down. */
    public function decide(IngredientRequest $request, User $by, bool $add, ?string $group = null, ?string $nameBn = null, ?string $note = null): IngredientRequest
    {
        if ($request->status !== 'pending') {
            throw new StockException('This request was already looked at.');
        }

        $ingredient = null;

        if ($add) {
            $ingredient = Ingredient::whereRaw('lower(name) = ?', [mb_strtolower($request->name)])->first()
                ?? $this->addIngredient($request->name, $nameBn, $group ?? $request->group ?? throw new StockException('Pick a group for the ingredient.'));
        }

        $request->update(['status' => $add ? 'added' : 'declined', 'ingredient_id' => $ingredient?->id, 'decided_by' => $by->id, 'decided_at' => now(), 'note' => $note ? mb_substr(trim($note), 0, 200) : $request->note]);

        return $request->fresh();
    }

    // ------------------------------------------------------------------ what a restaurant has

    /** Tick an ingredient from the list and set this restaurant's own price. */
    public function tick(Restaurant $r, Ingredient $i, float $price): RestaurantIngredient
    {
        if (! $i->is_active) {
            throw new StockException('This ingredient is not in the list now.');
        }

        if ($price < 0 || $price > 5000) {
            throw new StockException('Check the price.');
        }

        return RestaurantIngredient::updateOrCreate(['restaurant_id' => $r->id, 'ingredient_id' => $i->id], ['price' => round($price, 2)]) ->fresh();
    }

    public function untick(Restaurant $r, Ingredient $i): void
    {
        RestaurantIngredient::where('restaurant_id', $r->id)->where('ingredient_id', $i->id)->delete();
        IngredientRule::where('restaurant_id', $r->id)->where(fn ($q) => $q->where('ingredient_a_id', $i->id)->orWhere('ingredient_b_id', $i->id))->delete();
    }

    public function setAvailable(RestaurantIngredient $ri, bool $on): void
    {
        $ri->update(['available' => $on]);
    }

    /** "Sold out today": back on by itself tomorrow. */
    public function soldOutToday(RestaurantIngredient $ri, bool $on): void
    {
        $ri->update(['sold_out_on' => $on ? Restaurants::now()->toDateString() : null]);
    }

    // ------------------------------------------------------------------ bases, limits, rules

    public function saveBase(Restaurant $r, array $data, ?FoodBase $base = null): FoodBase
    {
        $kind = $data['kind'] ?? $base?->kind;
        $price = $data['price'] ?? $base?->price;

        if (! in_array($kind, FoodBase::KINDS, true)) {
            throw new StockException('Pick burger, pizza, sandwich or roll.');
        }

        if (! is_numeric($price) || (float) $price <= 0 || (float) $price > 100000) {
            throw new StockException('A price must be more than zero.');
        }

        $fields = ['kind' => $kind, 'name' => mb_substr(trim((string) ($data['name'] ?? $base?->name ?? ucfirst($kind))), 0, 80), 'price' => round((float) $price, 2), 'cook_minutes' => max(0, min(240, (int) ($data['cook_minutes'] ?? $base?->cook_minutes ?? 15)))];

        if ($base) {
            abort_unless($base->restaurant_id === $r->id, 404);
            $base->update($fields);

            return $base->fresh();
        }

        return FoodBase::create($fields + ['restaurant_id' => $r->id, 'available' => true]);
    }

    public function setBaseAvailable(FoodBase $base, bool $on): void
    {
        $base->update(['available' => $on]);
    }

    public function soldOutBase(FoodBase $base, bool $on): void
    {
        $base->update(['sold_out_on' => $on ? Restaurants::now()->toDateString() : null]);
    }

    public function setLimits(Restaurant $r, int $max, int $extraMinutes): void
    {
        if ($max < 1 || $max > 30 || $extraMinutes < 0 || $extraMinutes > 120) {
            throw new StockException('Check the numbers.');
        }

        $r->update(['max_ingredients' => $max, 'custom_extra_minutes' => $extraMinutes]);
    }

    public function addRule(Restaurant $r, Ingredient $a, Ingredient $b): IngredientRule
    {
        if ($a->id === $b->id) {
            throw new StockException('Pick two different ingredients.');
        }

        [$x, $y] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        return IngredientRule::firstOrCreate(['restaurant_id' => $r->id, 'ingredient_a_id' => $x, 'ingredient_b_id' => $y]);
    }

    // ------------------------------------------------------------------ building a dish

    /**
     * Check a build and price it. Nothing here trusts the browser: the base, each ingredient, the limit and the rules are all read again.
     *
     * @param  array<int, int|string>  $ingredientIds
     * @return array{base: FoodBase, picked: Collection<int, array{id: int, name: string, price: float}>, unit_price: float, minutes: int}
     *
     * @throws StockException
     */
    public function build(Restaurant $r, int $baseId, array $ingredientIds): array
    {
        $base = FoodBase::where('restaurant_id', $r->id)->find($baseId);

        if (! $base || ! $base->isOrderable()) {
            throw new StockException('This base is not available now.');
        }

        $ids = collect($ingredientIds)->map(fn ($i) => (int) $i)->unique()->values();

        if ($ids->count() > $r->max_ingredients) {
            throw new StockException(__('You can put at most {n} things in one dish.', ['n' => $r->max_ingredients]));
        }

        $have = RestaurantIngredient::where('restaurant_id', $r->id)->whereIn('ingredient_id', $ids)->with('ingredient')->get()->keyBy('ingredient_id');
        $picked = collect();

        foreach ($ids as $id) {
            $ri = $have->get($id);

            if (! $ri || ! $ri->ingredient->is_active || ! $ri->isOrderable()) {
                throw new StockException(__(':name is not available now.', ['name' => $ri?->ingredient->name ?? __('This ingredient')]));
            }

            $picked->push(['id' => $id, 'name' => $ri->ingredient->name, 'price' => (float) $ri->price]);
        }

        $rules = IngredientRule::where('restaurant_id', $r->id)->get();
        $names = $picked->pluck('name', 'id');

        foreach ($rules as $rule) {
            if ($ids->contains($rule->ingredient_a_id) && $ids->contains($rule->ingredient_b_id)) {
                throw new StockException(__(':a does not go with :b.', ['a' => $names[$rule->ingredient_a_id], 'b' => $names[$rule->ingredient_b_id]]));
            }
        }

        return ['base' => $base, 'picked' => $picked, 'unit_price' => round((float) $base->price + $picked->sum('price'), 2), 'minutes' => $base->cook_minutes + $r->custom_extra_minutes];
    }

    /** Everything the build screen needs: bases, ingredients by group with this restaurant's price, the pairs that do not go together, the limit. */
    public function menu(Restaurant $r): array
    {
        $bases = FoodBase::where('restaurant_id', $r->id)->orderBy('price')->get()->filter(fn (FoodBase $b) => $b->isOrderable());
        $ingredients = RestaurantIngredient::where('restaurant_id', $r->id)->with('ingredient')->get()->filter(fn (RestaurantIngredient $ri) => $ri->ingredient->is_active);

        return [
            'bases' => $bases->map(fn (FoodBase $b) => ['id' => $b->id, 'kind' => $b->kind, 'name' => $b->name, 'price' => (float) $b->price, 'cook_minutes' => $b->cook_minutes])->values(),
            'ingredients' => $ingredients->map(fn (RestaurantIngredient $ri) => [
                'id' => $ri->ingredient_id, 'name' => app()->getLocale() === 'bn' && $ri->ingredient->name_bn ? $ri->ingredient->name_bn : $ri->ingredient->name, 'search' => mb_strtolower($ri->ingredient->name.' '.$ri->ingredient->name_bn),
                'group' => $ri->ingredient->group, 'price' => (float) $ri->price, 'sold_out' => ! $ri->isOrderable(),
            ])->sortBy(['group', 'name'])->values(),
            'rules' => IngredientRule::where('restaurant_id', $r->id)->get(['ingredient_a_id as a', 'ingredient_b_id as b']),
            'max' => $r->max_ingredients, 'extra' => $r->custom_extra_minutes,
        ];
    }

    // ------------------------------------------------------------------ favourites

    public function saveFavorite(User $user, Restaurant $r, string $name, int $baseId, array $ingredientIds): FoodFavorite
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw new StockException('Give your favourite a name.');
        }

        $built = $this->build($r, $baseId, $ingredientIds);

        if (FoodFavorite::where('user_id', $user->id)->count() >= 30) {
            throw new StockException('That is a lot of favourites. Remove one first.');
        }

        return FoodFavorite::updateOrCreate(['user_id' => $user->id, 'restaurant_id' => $r->id, 'name' => $name], ['food_base_id' => $built['base']->id, 'ingredient_ids' => $built['picked']->pluck('id')->all()]);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function favorites(User $user, Restaurant $r): Collection
    {
        return FoodFavorite::where('user_id', $user->id)->where('restaurant_id', $r->id)->orderBy('name')->get()->map(function (FoodFavorite $f) use ($r) {
            // A favourite whose base or ingredient has run out today is still listed, but says so.
            try {
                $built = $this->build($r, $f->food_base_id, (array) $f->ingredient_ids);

                return ['id' => $f->id, 'name' => $f->name, 'base_id' => $f->food_base_id, 'ingredient_ids' => (array) $f->ingredient_ids, 'price' => $built['unit_price'], 'ok' => true, 'problem' => null];
            } catch (StockException $e) {
                return ['id' => $f->id, 'name' => $f->name, 'base_id' => $f->food_base_id, 'ingredient_ids' => (array) $f->ingredient_ids, 'price' => null, 'ok' => false, 'problem' => __($e->getMessage())];
            }
        });
    }

    // ------------------------------------------------------------------ for us

    /** Which ingredient sells most, across all restaurants (spec ch 17: seen in reports). @return Collection<int, array{name: string, count: int}> */
    public function topSelling(int $limit = 15): Collection
    {
        $counts = [];

        FoodOrderItem::whereNotNull('ingredients')->whereHas('order', fn ($q) => $q->where('status', 'delivered'))->get(['ingredients', 'quantity'])->each(function (FoodOrderItem $line) use (&$counts) {
            foreach ((array) ($line->ingredients['items'] ?? []) as $i) {
                $counts[$i['name']] = ($counts[$i['name']] ?? 0) + $line->quantity;
            }
        });

        arsort($counts);

        return collect($counts)->take($limit)->map(fn ($n, $name) => ['name' => $name, 'count' => (int) $n])->values();
    }
}
