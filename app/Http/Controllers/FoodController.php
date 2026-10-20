<?php

namespace App\Http\Controllers;

use App\Models\FoodFavorite;
use App\Models\FoodItem;
use App\Models\FoodOrder;
use App\Models\Restaurant;
use App\Services\Food\CustomFood;
use App\Services\Food\FoodOrders;
use App\Services\Food\Restaurants;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Food from local restaurants (spec ch 17): the buyer's area first, then only the restaurants that deliver there, then the menu of what can be
 * ordered now. Open to everyone; no account needed to order. The restaurant's commission and money are never shown here.
 */
class FoodController extends Controller
{
    public function __construct(private Restaurants $restaurants, private FoodOrders $orders, private CustomFood $custom) {}

    public function index(Request $request): Response
    {
        $area = trim((string) ($request->query('area') ?? $request->session()->get('food_area') ?? $request->user()?->area ?? ''));

        if ($request->query('area') !== null) {
            $request->session()->put('food_area', $area);
        }

        // How the meal will be had: brought to the door (the buyer's area decides who delivers) or eaten at the restaurant.
        $mode = $request->query('mode') === 'dine' ? 'dine' : 'delivery';
        $all = Restaurant::where('status', 'active')->get();
        $list = $mode === 'dine'
            ? $all->filter(fn (Restaurant $r) => (bool) $r->eat_there)->sortBy(fn (Restaurant $r) => [$this->restaurants->isOpenNow($r) ? 0 : 1, $r->name])->values()
            : ($area === '' ? $all->sortBy(fn (Restaurant $r) => [$this->restaurants->isOpenNow($r) ? 0 : 1, $r->name])->values() : $this->restaurants->near($area));

        // Dishes with a photograph, from restaurants that are on, for the big promotion banners.
        $featured = FoodItem::query()->with('restaurant:id,name,status')->where('available', true)->whereNotNull('photo_path')
            ->whereHas('restaurant', fn ($q) => $q->where('status', 'active'))->orderByDesc('id')->limit(6)->get()
            ->map(fn (FoodItem $i) => ['id' => $i->id, 'restaurant' => $i->restaurant_id, 'restaurant_name' => $i->restaurant->name, 'name' => $i->name, 'category' => $i->category, 'price' => (float) $i->price, 'photo' => route('food.item-photo', $i)])->values();

        // A chosen kind of food narrows the page to it: its dishes, and the restaurants that serve it.
        $category = trim((string) $request->query('category', ''));
        $category = $category !== '' && FoodItem::where('available', true)->where('category', $category)->exists() ? $category : null;
        $cards = $list->when($category !== null, fn ($l) => $l->filter(fn (Restaurant $r) => FoodItem::where('restaurant_id', $r->id)->where('available', true)->where('category', $category)->exists()))->map(fn (Restaurant $r) => $this->card($r))->values();

        return Inertia::render('food/index', [
            'front' => app(\App\Services\Storefront\PageFronts::class)->food($cards->all(), $category),
            'area' => $area,
            'mode' => $mode,
            'restaurants' => $list->map(fn (Restaurant $r) => $this->card($r))->values(),
            'featured' => $featured,
            'areas' => Restaurant::where('status', 'active')->get('areas')->pluck('areas')->flatten()->unique()->sort()->values(),
        ]);
    }

    public function show(Request $request, Restaurant $restaurant): Response
    {
        // A suspended restaurant does not exist for buyers.
        abort_unless($restaurant->status === 'active', 404);
        $area = (string) ($request->session()->get('food_area') ?? $request->user()?->area ?? '');

        return Inertia::render('food/show', [
            'restaurant' => $this->card($restaurant) + ['eat_there' => (bool) $restaurant->eat_there, 'address' => $restaurant->address, 'manual_charge' => $restaurant->charge_mode === 'manual', 'advance_over' => $restaurant->advance_over !== null ? (float) $restaurant->advance_over : null, 'advance_percent' => $restaurant->advance_percent],
            'items' => $this->restaurants->menu($restaurant)->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'description' => $i->description, 'category' => $i->category, 'price' => (float) $i->price, 'photo' => $i->photo_path !== null ? route('food.item-photo', $i) : null, 'ready_made' => $i->ready_made, 'cook_minutes' => $i->cook_minutes])->values(),
            'area' => $area, 'serves' => $area !== '' && $this->restaurants->serves($restaurant, $area),
            'charge' => $area !== '' ? $this->orders->charge($restaurant, $area)[0] : null,
            // Build your own (spec ch 17): the bases, the ingredients at this restaurant's own prices, what does not go together, the limit.
            'custom' => $this->custom->menu($restaurant), 'favorites' => $request->user() ? $this->custom->favorites($request->user(), $restaurant)->values() : [],
            'eatOptions' => $this->restaurants->eatOptions(), 'me' => $request->user() ? ['name' => $request->user()->name, 'phone' => $request->user()->phone] : null,
        ]);
    }

    public function store(Request $request, Restaurant $restaurant): RedirectResponse
    {
        abort_unless($restaurant->status === 'active', 404);
        $request->merge(['phone' => $this->phone((string) $request->input('phone'))]);
        $data = $request->validate([
            'mode' => ['required', 'in:delivery,eat_there'], 'lines' => ['required', 'array', 'min:1', 'max:40'], 'lines.*.item_id' => ['nullable', 'integer'], 'lines.*.base_id' => ['nullable', 'integer'], 'lines.*.ingredient_ids' => ['nullable', 'array', 'max:30'], 'lines.*.ingredient_ids.*' => ['integer'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'lines.*.note' => ['nullable', 'string', 'max:120'], 'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'area' => ['nullable', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:250'], 'how_to_find' => ['nullable', 'string', 'max:200'], 'eat_time' => ['nullable', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:300'],
        ], ['phone.regex' => __('Write a Bangladesh mobile number, like 01712345678.')]);

        try {
            $order = $this->orders->place($restaurant, $request->user(), $data['lines'], $data['mode'], collect($data)->only(['name', 'phone', 'area', 'address', 'how_to_find'])->all(), $data['eat_time'] ?? null, $data['note'] ?? null);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return to_route('food.order', $order->public_token)->with('status', __('Your order is sent. You will see "Pending" until the restaurant confirms.'));
    }

    public function saveFavorite(Request $request): RedirectResponse
    {
        $data = $request->validate(['restaurant_id' => ['required', 'integer', 'exists:restaurants,id'], 'name' => ['required', 'string', 'max:60'], 'base_id' => ['required', 'integer'], 'ingredient_ids' => ['nullable', 'array', 'max:30'], 'ingredient_ids.*' => ['integer']]);
        $restaurant = Restaurant::where('status', 'active')->findOrFail($data['restaurant_id']);

        return $this->act(fn () => $this->custom->saveFavorite($request->user(), $restaurant, $data['name'], (int) $data['base_id'], $data['ingredient_ids'] ?? []), 'Saved. Next time it is one tap.');
    }

    public function forgetFavorite(Request $request, FoodFavorite $favorite): RedirectResponse
    {
        abort_unless($favorite->user_id === $request->user()->id, 404);
        $favorite->delete();

        return back()->with('status', __('Removed.'));
    }

    public function order(string $token): Response
    {
        return Inertia::render('food/order', ['order' => $this->orders->forBuyer($this->find($token))]);
    }

    public function mine(Request $request): Response
    {
        return Inertia::render('food/orders', [
            'orders' => FoodOrder::where('buyer_id', $request->user()->id)->with('restaurant:id,name')->latest('id')->limit(40)->get()
                ->map(fn (FoodOrder $o) => ['token' => $o->public_token, 'number' => $o->number(), 'restaurant' => $o->restaurant->name, 'status' => $o->status, 'total' => (float) $o->total, 'at' => $o->created_at->toIso8601String()]),
        ]);
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        return $this->act(fn () => $this->orders->cancel($this->find($token), 'buyer', $request->input('reason')), 'Order cancelled.');
    }

    public function complain(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'min:3', 'max:500'], 'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:6144']]);

        return $this->act(fn () => $this->orders->complain($this->find($token), $data['text'], $request->file('photo')), 'Sent to the restaurant and to us.');
    }

    public function complaintPhoto(string $token): StreamedResponse
    {
        $order = $this->find($token);
        abort_unless($order->complaint_photo !== null && Storage::exists($order->complaint_photo), 404);

        return Storage::response($order->complaint_photo, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function itemPhoto(FoodItem $item): StreamedResponse
    {
        abort_unless($item->photo_path !== null && Storage::exists($item->photo_path) && $item->restaurant->status === 'active', 404);

        return Storage::response($item->photo_path, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    public function restaurantPhoto(Restaurant $restaurant): StreamedResponse
    {
        abort_unless($restaurant->photo_path !== null && Storage::exists($restaurant->photo_path) && $restaurant->status === 'active', 404);

        return Storage::response($restaurant->photo_path, null, ['Cache-Control' => 'public, max-age=86400']);
    }

    private function card(Restaurant $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'description' => $r->description, 'area' => $r->area, 'open' => $this->restaurants->isOpenNow($r), 'photo' => $r->photo_path !== null ? route('food.photo', $r) : null,
            'hours' => $r->opens_at.' – '.$r->closes_at,
        ];
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    private function find(string $token): FoodOrder
    {
        return FoodOrder::where('public_token', $token)->firstOrFail();
    }

    private function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }
}
