<?php

namespace App\Services\Storefront;

use App\Http\Controllers\FormController;
use App\Models\Company;
use App\Models\FoodItem;
use App\Models\FoodOrderItem;
use App\Models\GameOrder;
use App\Models\GamePackage;
use App\Models\Medicine;
use App\Models\StorefrontBlock;
use App\Support\MedicinePresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The front pages of pharmacy, food and game top-up, as the admin built them (Admin > Shop fronts): the active sections in order, each with the live
 * data it needs and its words in the viewer's language. A page with no active sections gets null and shows its plain layout.
 */
class PageFronts
{
    /** @return Collection<int, StorefrontBlock> */
    private function blocks(string $page): Collection
    {
        return StorefrontBlock::where('page', $page)->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
    }

    private function base(StorefrontBlock $b): array
    {
        return [
            'id' => $b->id, 'kind' => $b->kind, 'title' => $b->say('title'), 'text' => $b->say('text'), 'button' => $b->say('button'),
            'image' => $b->image_path ? route('market.storefront-image', ['block' => $b->id, 'v' => substr(md5($b->image_path.$b->updated_at), 0, 8)]) : null,
        ];
    }

    /**
     * Pharmacy. On the front (nothing searched, no letter chosen) every section shows; otherwise only the banner with its search box and the list.
     *
     * @return array{blocks: array<int, array<string, mixed>>}|null
     */
    public function medicines(bool $front): ?array
    {
        $blocks = $this->blocks('medicines');

        if ($blocks->isEmpty()) {
            return null;
        }

        $counts = Cache::remember('medicine.counts', 600, fn () => ['brands' => Medicine::active()->count(), 'generics' => Medicine::active()->whereNotNull('generic_name')->distinct()->count('generic_name')]);
        $out = [];

        foreach ($blocks as $b) {
            $row = $this->base($b);

            if (! $front && ! in_array($b->kind, ['hero', 'browse'], true)) {
                continue;
            }

            switch ($b->kind) {
                case 'hero':
                    $row['counts'] = $counts;
                    break;
                case 'forms':
                    $row['forms'] = collect(FormController::forms())->take(max(1, $b->item_limit))->map(fn ($f, $slug) => ['slug' => $slug, 'name' => $f['name'], 'count' => $f['count']])->values()->all();
                    if ($row['forms'] === []) {
                        continue 2;
                    }
                    break;
                case 'fresh':
                    $row['medicines'] = MedicinePresenter::many(Medicine::active()->with('company')->latest('id')->limit(max(1, $b->item_limit))->get());
                    if ($row['medicines'] === []) {
                        continue 2;
                    }
                    break;
                case 'companies':
                    $row['companies'] = $this->companies(max(1, $b->item_limit));
                    if ($row['companies'] === []) {
                        continue 2;
                    }
                    break;
                case 'service':
                case 'faq':
                case 'steps':
                    $row['items'] = $b->itemList();
                    if ($row['items'] === []) {
                        continue 2;
                    }
                    break;
            }

            $out[] = $row;
        }

        return ['blocks' => $out];
    }

    /** The companies with the most medicines, with their logos. */
    private function companies(int $limit): array
    {
        return Cache::remember('storefront.companies.'.$limit, 600, function () use ($limit) {
            $ids = Medicine::active()->whereNotNull('company_id')->selectRaw('company_id, count(*) as n')->groupBy('company_id')->orderByDesc('n')->limit($limit)->pluck('n', 'company_id');

            return Company::whereIn('id', $ids->keys())->get()->sortByDesc(fn (Company $c) => $ids[$c->id])->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'logo' => $c->logo, 'count' => (int) $ids[$c->id]])->values()->all();
        });
    }

    /**
     * Food. The restaurant cards and the chosen way of having the meal come from the page; the dishes and categories come from the menus.
     *
     * @param  array<int, array<string, mixed>>  $restaurants
     * @return array{blocks: array<int, array<string, mixed>>, category: string|null}|null
     */
    public function food(array $restaurants, ?string $category): ?array
    {
        $blocks = $this->blocks('food');

        if ($blocks->isEmpty()) {
            return null;
        }

        $dishes = FoodItem::query()->with('restaurant:id,name,status')->where('available', true)->whereHas('restaurant', fn ($q) => $q->where('status', 'active'))->get();
        $sold = FoodOrderItem::selectRaw('food_item_id, sum(quantity) as n')->groupBy('food_item_id')->pluck('n', 'food_item_id');
        $card = fn (FoodItem $i) => [
            'id' => $i->id, 'restaurant' => $i->restaurant_id, 'restaurant_name' => $i->restaurant->name, 'name' => $i->name, 'description' => $i->description, 'category' => $i->category,
            'price' => (float) $i->price, 'photo' => $i->photo_path !== null ? route('food.item-photo', $i) : null,
        ];
        $pool = function (?string $source) use ($dishes, $sold) {
            if ($source === 'best') {
                return $dishes->sortByDesc(fn (FoodItem $i) => [$sold[$i->id] ?? 0, $i->id])->values();
            }
            if ($source === 'top') {
                return $dishes->sortByDesc('price')->values();
            }
            if (is_string($source) && str_starts_with($source, 'category:')) {
                return $dishes->where('category', substr($source, 9))->sortByDesc('id')->values();
            }
            if (is_numeric($source)) {
                return $dishes->where('id', (int) $source)->values();
            }

            return $dishes->sortByDesc('id')->values();
        };
        $used = [];
        $take = function (Collection $set, int $limit) use (&$used, $card) {
            $out = $set->filter(fn (FoodItem $i) => ! in_array($i->id, $used, true))->take($limit)->values();
            foreach ($out as $i) {
                $used[] = $i->id;
            }

            return $out->map($card)->all();
        };

        $categories = $dishes->filter(fn (FoodItem $i) => $i->category)->groupBy('category')->map(fn (Collection $g, string $name) => [
            'name' => $name, 'count' => $g->count(), 'photo' => ($withPhoto = $g->first(fn (FoodItem $i) => $i->photo_path !== null)) ? route('food.item-photo', $withPhoto) : null,
        ])->sortByDesc('count')->values()->all();

        $out = [];

        foreach ($blocks as $b) {
            $row = $this->base($b);

            // A chosen category narrows the page to that category: its dishes, the categories to switch to, and the restaurants that serve it.
            if ($category !== null && ! in_array($b->kind, ['hero', 'cuisines', 'restaurants'], true)) {
                continue;
            }

            switch ($b->kind) {
                case 'hero':
                    $hero = $pool('best')->first(fn (FoodItem $i) => $i->photo_path !== null);
                    $row['dish'] = $hero ? $card($hero) : null;
                    break;
                case 'cuisines':
                    $row['categories'] = array_slice($categories, 0, max(1, $b->item_limit));
                    $row['current'] = $category;
                    if ($row['categories'] === []) {
                        continue 2;
                    }
                    break;
                case 'dishes':
                    $row['dishes'] = $take($pool($b->source), max(1, $b->item_limit));
                    if ($row['dishes'] === []) {
                        continue 2;
                    }
                    break;
                case 'banner':
                    $dish = $take($pool($b->source ?? 'fresh'), 1)[0] ?? null;
                    if (! $dish) {
                        continue 2;
                    }
                    $row['dish'] = $dish;
                    $row['image'] = $row['image'] ?? $dish['photo'];
                    break;
                case 'restaurants':
                    $row['restaurants'] = array_slice($restaurants, 0, max(1, $b->item_limit));
                    break;
                case 'service':
                case 'faq':
                case 'steps':
                    $row['items'] = $b->itemList();
                    if ($row['items'] === []) {
                        continue 2;
                    }
                    break;
            }

            $out[] = $row;
        }

        if ($category !== null) {
            $mine = $dishes->where('category', $category)->sortByDesc('id')->values();
            $at = collect($out)->search(fn ($r) => $r['kind'] === 'cuisines');
            $list = ['id' => 0, 'kind' => 'dishes', 'title' => $category, 'text' => null, 'button' => null, 'image' => null, 'dishes' => $mine->map($card)->all(), 'source' => null];
            array_splice($out, $at === false ? 1 : $at + 1, 0, [$list]);
        }

        return ['blocks' => $out, 'category' => $category];
    }

    /**
     * Game top-up. The game cards are built by the page (cover, number of packages); the packs shelf looks across every game.
     *
     * @param  array<int, array<string, mixed>>  $games
     * @return array{blocks: array<int, array<string, mixed>>}|null
     */
    public function gaming(array $games): ?array
    {
        $blocks = $this->blocks('gaming');

        if ($blocks->isEmpty()) {
            return null;
        }

        $out = [];

        foreach ($blocks as $b) {
            $row = $this->base($b);

            switch ($b->kind) {
                case 'games':
                    $row['games'] = array_slice(array_map(fn ($g) => collect($g)->only(['name', 'slug', 'packages', 'cover', 'from'])->all(), $games), 0, max(1, $b->item_limit));
                    if ($row['games'] === []) {
                        continue 2;
                    }
                    break;
                case 'packs':
                    $row['packs'] = $this->packs($b->source, max(1, $b->item_limit));
                    if ($row['packs'] === []) {
                        continue 2;
                    }
                    break;
                case 'service':
                case 'faq':
                case 'steps':
                    $row['items'] = $b->itemList();
                    if ($row['items'] === []) {
                        continue 2;
                    }
                    break;
            }

            $out[] = $row;
        }

        return ['blocks' => $out];
    }

    /** Packs from games that are on sale: the lowest priced, the most ordered, or those of one game. */
    private function packs(?string $source, int $limit): array
    {
        $q = GamePackage::query()->with('game')->where('is_active', true)->whereHas('game', fn ($g) => $g->where('is_active', true));

        if (is_string($source) && str_starts_with($source, 'game:')) {
            $q->whereHas('game', fn ($g) => $g->where('slug', substr($source, 5)));
        }

        $all = $q->get();

        if ($source === 'popular') {
            $sold = GameOrder::selectRaw('game_package_id, count(*) as n')->groupBy('game_package_id')->pluck('n', 'game_package_id');
            $all = $all->sortByDesc(fn (GamePackage $p) => [$sold[$p->id] ?? 0, -(float) $p->price]);
        } else {
            $all = $all->sortBy(fn (GamePackage $p) => (float) $p->price);
        }

        return $all->take($limit)->map(fn (GamePackage $p) => [
            'id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price, 'game' => $p->game->name, 'slug' => $p->game->slug,
            'cover' => $p->game->photo_path ? route('gaming.cover', ['game' => $p->game->slug, 'v' => substr(md5($p->game->photo_path), 0, 8)]) : null,
        ])->values()->all();
    }
}
