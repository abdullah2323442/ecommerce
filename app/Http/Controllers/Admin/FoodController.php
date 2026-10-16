<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FoodOrder;
use App\Models\Restaurant;
use App\Models\Ingredient;
use App\Models\IngredientRequest;
use App\Services\Food\CustomFood;
use App\Services\Food\Restaurants;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** We open restaurant accounts, set each one's commission by hand, watch every order and see what has accumulated (spec ch 17). */
class FoodController extends Controller
{
    public function __construct(private Restaurants $restaurants, private CustomFood $custom) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-accounts');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : Restaurants::now()->format('Y-m');

        return Inertia::render('admin/food', [
            'month' => $month,
            'restaurants' => Restaurant::with('owner:id,name,email,phone')->orderBy('name')->get()->map(fn (Restaurant $r) => [
                'id' => $r->id, 'name' => $r->name, 'area' => $r->area, 'owner' => $r->owner->name, 'phone' => $r->phone ?? $r->owner->phone, 'status' => $r->status, 'open' => $this->restaurants->isOpenNow($r),
                'commission_kind' => $r->commission_kind, 'commission_value' => (float) $r->commission_value, 'sales' => $this->restaurants->sales($r, $month),
                'active_orders' => $r->orders()->whereIn('status', FoodOrder::OPEN)->count(),
            ]),
            'orders' => FoodOrder::with('restaurant:id,name')->latest('id')->limit(40)->get()->map(fn (FoodOrder $o) => [
                'id' => $o->id, 'number' => $o->number(), 'restaurant' => $o->restaurant->name, 'status' => $o->status, 'mode' => $o->mode, 'total' => (float) $o->total, 'commission' => (float) $o->commission,
                'at' => $o->created_at->toIso8601String(), 'complaint' => $o->complaint, 'has_complaint_photo' => $o->complaint_photo !== null,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'owner_name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'], 'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'max:100'], 'area' => ['required', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:250'], 'areas' => ['nullable', 'array', 'max:40'], 'areas.*' => ['string', 'max:80'],
            'commission_kind' => ['required', 'in:percent,taka'], 'commission_value' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        try {
            $this->restaurants->open($data, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['food' => __($e->getMessage())]);
        }

        return back()->with('status', __('Restaurant opened.'));
    }

    public function commission(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['kind' => ['required', 'in:percent,taka'], 'value' => ['required', 'numeric', 'min:0', 'max:100000']]);

        try {
            $this->restaurants->setCommission($restaurant, $data['kind'], (float) $data['value'], $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['food' => __($e->getMessage())]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function suspend(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->restaurants->suspend($restaurant, (bool) $data['on'], $request->user());

        return back()->with('status', $data['on'] ? __('Suspended. It takes no new orders. Orders already made must still be completed.') : __('Back on.'));
    }

    // ------------------------------------------------------------------ the central ingredient list

    /** The one list of ingredients, ours alone; what restaurants ask us to add; what sells most. */
    public function ingredients(): Response
    {
        $this->authorize('manage-accounts');

        return Inertia::render('admin/food-ingredients', [
            'groups' => Ingredient::GROUPS,
            'ingredients' => Ingredient::orderBy('group')->orderBy('name')->get(),
            'requests' => IngredientRequest::where('status', 'pending')->with('restaurant:id,name')->oldest()->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'group' => $r->group, 'note' => $r->note, 'restaurant' => $r->restaurant->name]),
            'top' => $this->custom->topSelling(),
        ]);
    }

    public function storeIngredient(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'name_bn' => ['nullable', 'string', 'max:80'], 'group' => ['required', 'in:meat,cheese,vegetables,sauces,extras,spices']]);

        return $this->attempt(fn () => $this->custom->addIngredient($data['name'], $data['name_bn'] ?? null, $data['group']), 'Added to the list. Every restaurant can tick it.');
    }

    public function updateIngredient(Request $request, Ingredient $ingredient): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['name_bn' => ['nullable', 'string', 'max:80'], 'group' => ['required', 'in:meat,cheese,vegetables,sauces,extras,spices'], 'is_active' => ['required', 'boolean']]);

        return $this->attempt(fn () => $this->custom->updateIngredient($ingredient, $data), 'Saved.');
    }

    public function decideRequest(Request $request, IngredientRequest $ingredientRequest): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['decision' => ['required', 'in:add,decline'], 'group' => ['nullable', 'in:meat,cheese,vegetables,sauces,extras,spices'], 'name_bn' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->attempt(fn () => $this->custom->decide($ingredientRequest, $request->user(), $data['decision'] === 'add', $data['group'] ?? null, $data['name_bn'] ?? null, $data['note'] ?? null), 'Done.');
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['food' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    public function complaintPhoto(FoodOrder $order): StreamedResponse
    {
        $this->authorize('manage-accounts');
        abort_unless($order->complaint_photo !== null && Storage::exists($order->complaint_photo), 404);

        return Storage::response($order->complaint_photo, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
