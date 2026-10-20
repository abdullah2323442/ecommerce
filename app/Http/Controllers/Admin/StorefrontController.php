<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\FoodItem;
use App\Models\Game;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\StorefrontBlock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin > Shop fronts: the owner builds the front page of each shop, and of pharmacy, food and game top-up, from sections, in English and Bengali. */
class StorefrontController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-accounts');

        $sectors = Sector::where('is_active', true)->orderBy('sort')->orderBy('id')->get();
        $page = array_key_exists((string) $request->query('page'), StorefrontBlock::PAGES) ? (string) $request->query('page') : null;
        $chosen = $page === null ? ($sectors->firstWhere('slug', $request->query('sector')) ?? $sectors->firstWhere('slug', 'electronics') ?? $sectors->first()) : null;

        $query = StorefrontBlock::query()->orderBy('sort')->orderBy('id');
        $page !== null ? $query->where('page', $page) : $query->where('sector_id', $chosen?->id ?? 0);

        $blocks = $query->get()->map(fn (StorefrontBlock $b) => [
            'id' => $b->id, 'kind' => $b->kind, 'is_active' => $b->is_active, 'title' => $b->title, 'title_bn' => $b->title_bn, 'text' => $b->text, 'text_bn' => $b->text_bn,
            'button' => $b->button, 'button_bn' => $b->button_bn, 'source' => $b->source, 'item_limit' => $b->item_limit, 'items' => $b->items ?? [],
            'image' => $b->image_path ? route('market.storefront-image', ['block' => $b->id, 'v' => substr(md5($b->image_path.$b->updated_at), 0, 8)]) : null,
        ])->values();

        return Inertia::render('admin/storefront', [
            'sectors' => $sectors->map(fn (Sector $s) => ['id' => $s->id, 'slug' => $s->slug, 'name' => $s->name, 'sections' => StorefrontBlock::where('sector_id', $s->id)->where('is_active', true)->count()])->values(),
            'pages' => collect(StorefrontBlock::PAGES)->map(fn ($p, $key) => ['key' => $key, 'name' => $p['label'], 'sections' => StorefrontBlock::where('page', $key)->where('is_active', true)->count()])->values(),
            'sector' => $chosen?->slug,
            'page' => $page,
            'view' => $page !== null ? $this->pageUrl($page) : ($chosen ? route('market.index', ['sector' => $chosen->slug]) : null),
            'blocks' => $blocks,
            'kinds' => collect(StorefrontBlock::kindsFor($page))->map(fn ($k) => ['label' => $k['label'], 'fields' => $k['fields']]),
            'sources' => $this->sources($page, $chosen),
            'max' => StorefrontBlock::MAX_PER_SHOP,
        ]);
    }

    public function seed(Sector $sector): RedirectResponse
    {
        $this->authorize('manage-accounts');
        StorefrontBlock::seedDefaults($sector);

        return back()->with('status', __('The standard sections are in. Change them as you like.'));
    }

    public function seedPage(string $page): RedirectResponse
    {
        $this->authorize('manage-accounts');
        StorefrontBlock::seedPage($page);

        return back()->with('status', __('The standard sections are in. Change them as you like.'));
    }

    public function store(Request $request, Sector $sector): RedirectResponse
    {
        $this->authorize('manage-accounts');

        return $this->add($request, ['sector_id' => $sector->id], null);
    }

    public function storePage(Request $request, string $page): RedirectResponse
    {
        $this->authorize('manage-accounts');

        return $this->add($request, ['page' => $page], $page);
    }

    private function add(Request $request, array $owner, ?string $page): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(StorefrontBlock::kindsFor($page)))]]);
        $scope = StorefrontBlock::query()->where($owner);

        if ((clone $scope)->count() >= StorefrontBlock::MAX_PER_SHOP) {
            return back()->withErrors(['kind' => __('A shop front can have at most :n sections.', ['n' => StorefrontBlock::MAX_PER_SHOP])]);
        }

        $defaults = match ($data['kind']) {
            'products' => ['title' => 'New shelf', 'source' => 'fresh', 'item_limit' => 8],
            'premium' => ['title' => 'Premium picks', 'source' => 'premium', 'item_limit' => 4],
            'categories' => ['title' => 'Shop by category', 'item_limit' => 8],
            'banner' => ['title' => 'Featured', 'button' => 'See this piece', 'source' => $page === 'food' ? 'fresh' : 'top'],
            'budget' => ['title' => 'Shop by budget'],
            'faq' => ['title' => 'Questions before you buy', 'items' => [['title' => 'A question', 'text' => 'Its answer.']]],
            'service' => ['items' => [['title' => 'A promise', 'text' => 'What it means for the buyer.']]],
            'steps' => ['title' => 'How it works', 'items' => [['title' => 'First step', 'text' => 'What the buyer does.']]],
            'cta' => ['button' => 'Shop all {n} products'],
            'forms' => ['title' => 'Browse by form', 'item_limit' => 8],
            'companies' => ['title' => 'Pharmaceutical companies', 'item_limit' => 12],
            'fresh' => ['title' => 'Newly added', 'item_limit' => 8],
            'browse' => ['title' => 'All medicines, A to Z'],
            'cuisines' => ['title' => 'What are you craving?', 'item_limit' => 8],
            'dishes' => ['title' => 'New shelf', 'source' => 'fresh', 'item_limit' => 6],
            'restaurants' => ['title' => 'Restaurants', 'item_limit' => 12],
            'games' => ['title' => 'Choose your game', 'item_limit' => 12],
            'packs' => ['title' => 'Best value packs', 'source' => 'value', 'item_limit' => 6],
            default => ['button' => 'Explore products'],
        };

        StorefrontBlock::create($owner + ['kind' => $data['kind'], 'sort' => (int) (clone $scope)->max('sort') + 10] + $defaults);

        return back()->with('status', __('Section added at the bottom. Move it where you want it.'));
    }

    public function update(Request $request, StorefrontBlock $block): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $block->loadMissing('sector');
        $sources = $this->sources($block->page, $block->sector);
        $sourceRule = ['nullable', 'string', 'max:60', function ($attribute, $value, $fail) use ($sources) {
            if ($value !== null && ! array_key_exists($value, $sources)) {
                $fail(__('Choose one of the listed sources.'));
            }
        }];

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'title' => ['nullable', 'string', 'max:160'], 'title_bn' => ['nullable', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:500'], 'text_bn' => ['nullable', 'string', 'max:500'],
            'button' => ['nullable', 'string', 'max:60'], 'button_bn' => ['nullable', 'string', 'max:60'],
            'source' => $sourceRule,
            'item_limit' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'items' => ['sometimes', 'array', 'max:8'],
            'items.*.title' => ['required', 'string', 'max:160'], 'items.*.title_bn' => ['nullable', 'string', 'max:160'],
            'items.*.text' => ['nullable', 'string', 'max:500'], 'items.*.text_bn' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($data['items'])) {
            $data['items'] = collect($data['items'])->map(fn ($i) => [
                'title' => $i['title'], 'title_bn' => $i['title_bn'] ?? null, 'text' => $i['text'] ?? null, 'text_bn' => $i['text_bn'] ?? null,
            ])->values()->all();
        }

        $block->update($data);

        return back()->with('status', __('Saved. The shop front shows it now.'));
    }

    public function move(Request $request, StorefrontBlock $block): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $dir = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $all = StorefrontBlock::query()
            ->when($block->page !== null, fn ($q) => $q->where('page', $block->page), fn ($q) => $q->where('sector_id', $block->sector_id))
            ->orderBy('sort')->orderBy('id')->get()->values();
        $i = $all->search(fn ($b) => $b->id === $block->id);
        $j = $dir === 'up' ? $i - 1 : $i + 1;

        if ($j >= 0 && $j < $all->count()) {
            $order = $all->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];

            foreach ($order as $n => $b) {
                $b->update(['sort' => ($n + 1) * 10]);
            }
        }

        return back();
    }

    public function destroy(StorefrontBlock $block): RedirectResponse
    {
        $this->authorize('manage-accounts');

        if ($block->image_path) {
            Storage::delete($block->image_path);
        }

        $block->delete();

        return back()->with('status', __('Section removed.'));
    }

    public function image(Request $request, StorefrontBlock $block): RedirectResponse
    {
        $this->authorize('manage-accounts');
        abort_unless(in_array('image', StorefrontBlock::KINDS[$block->kind]['fields'], true), 404);
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096']]);

        if ($block->image_path) {
            Storage::delete($block->image_path);
        }

        $block->update(['image_path' => $request->file('image')->store('storefront')]);

        return back()->with('status', __('Picture saved.'));
    }

    public function removeImage(StorefrontBlock $block): RedirectResponse
    {
        $this->authorize('manage-accounts');

        if ($block->image_path) {
            Storage::delete($block->image_path);
            $block->update(['image_path' => null]);
        }

        return back()->with('status', __('Picture removed.'));
    }

    private function pageUrl(string $page): string
    {
        return match ($page) {
            'medicines' => route('medicines.index'),
            'food' => route('food.index'),
            default => route('gaming.index'),
        };
    }

    /** What a shelf, banner or pack list can be filled from: key => label. */
    private function sources(?string $page, ?Sector $sector): array
    {
        if ($page === 'food') {
            $out = ['best' => 'Most ordered', 'fresh' => 'Newest', 'top' => 'The single highest priced'];

            foreach (FoodItem::where('available', true)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category') as $c) {
                $out['category:'.$c] = 'Category: '.$c;
            }

            foreach (FoodItem::where('available', true)->orderBy('name')->limit(200)->get(['id', 'name']) as $i) {
                $out[(string) $i->id] = 'Dish: '.$i->name;
            }

            return $out;
        }

        if ($page === 'gaming') {
            $out = ['value' => 'Lowest priced', 'popular' => 'Most ordered'];

            foreach (Game::where('is_active', true)->orderBy('name')->get(['slug', 'name']) as $g) {
                $out['game:'.$g->slug] = 'Game: '.$g->name;
            }

            return $out;
        }

        if ($page !== null || $sector === null) {
            return [];
        }

        $out = ['best' => 'Most ordered', 'fresh' => 'Newest', 'premium' => 'Highest priced', 'top' => 'The single highest priced'];

        foreach (Category::where('sector_id', $sector->id)->where('is_active', true)->orderBy('sort')->get() as $c) {
            $out['category:'.$c->slug] = 'Category: '.$c->name;
        }

        foreach (Listing::where('sector_id', $sector->id)->where('status', 'published')->whereNotNull('approved_at')->orderBy('title')->limit(200)->get(['id', 'title']) as $l) {
            $out[(string) $l->id] = 'Product: '.$l->title;
        }

        return $out;
    }
}
