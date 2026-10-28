<?php

namespace App\Services\Food;

use App\Enums\AccountType;
use App\Models\FoodItem;
use App\Models\FoodOrder;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Media\ImageCompressor;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Restaurants and their menus (spec ch 17). We open the account and set the commission by hand; the owner runs the menu, the hours,
 * the delivery and the advance. Customers see only restaurants that deliver to their area, and only dishes that can be ordered right now.
 */
class Restaurants
{
    public function __construct(private ActivityLogger $activity, private ImageCompressor $images) {}

    /** The time in Bangladesh: opening hours and "today" are Dhaka's, whatever the server says. */
    public static function now(): Carbon
    {
        return now('Asia/Dhaka');
    }

    // ------------------------------------------------------------------ opening an account (ours)

    /** @throws StockException */
    public function open(array $data, User $by): Restaurant
    {
        $area = trim((string) $data['area']);

        if ($area === '') {
            throw new StockException('Say which area the restaurant is in.');
        }

        return DB::transaction(function () use ($data, $by, $area) {
            $owner = new User;
            $owner->forceFill([
                'name' => $data['owner_name'], 'phone' => ($data['phone'] ?? null) ?: null, 'email' => ($data['email'] ?? null) ?: 'food-'.Str::lower(Str::random(8)).'@phone.invalid',
                'password' => Hash::make($data['password']), 'account_type' => AccountType::FoodVendor, 'area' => $area, 'email_verified_at' => now(),
            ])->save();

            return $this->makeRestaurant($owner, $data, $area, $by);
        });
    }

    /** An applicant's own account becomes the restaurant's owner (spec ch 3: a food vendor applies; we check and open it). */
    public function openFor(User $owner, array $data, User $by): Restaurant
    {
        $area = trim((string) ($data['area'] ?? ''));

        if ($area === '') {
            throw new StockException('Say which area the restaurant is in.');
        }

        if (Restaurant::where('owner_id', $owner->id)->exists()) {
            throw new StockException('This account already has a restaurant.');
        }

        return DB::transaction(function () use ($owner, $data, $by, $area) {
            $owner->forceFill(['account_type' => AccountType::FoodVendor, 'area' => $area, 'phone' => $owner->phone ?: ($data['phone'] ?? null)])->save();

            return $this->makeRestaurant($owner, $data, $area, $by);
        });
    }

    private function makeRestaurant(User $owner, array $data, string $area, User $by): Restaurant
    {
        return DB::transaction(function () use ($owner, $data, $area, $by) {
            $restaurant = Restaurant::create([
                'owner_id' => $owner->id, 'name' => $data['name'], 'phone' => ($data['phone'] ?? null) ?: null, 'address' => $data['address'] ?? null, 'area' => $area,
                'areas' => $this->areaList(array_merge([$area], (array) ($data['areas'] ?? []))), 'commission_kind' => $data['commission_kind'] ?? 'percent', 'commission_value' => $data['commission_value'] ?? 0,
                'status' => 'active', 'is_open' => true, 'opens_at' => '10:00', 'closes_at' => '22:00', 'delivery_by' => 'own', 'charge_mode' => 'area', 'charge_inside' => 30, 'charge_outside' => 60,
                'min_charge' => 0, 'eat_there' => true, 'advance_percent' => 0, 'max_ingredients' => 10, 'custom_extra_minutes' => 10,
            ]);
            $this->activity->record('restaurant.opened', $owner, ['restaurant' => $restaurant->name], $by);

            return $restaurant;
        });
    }

    /** We set the commission by hand, restaurant by restaurant. */
    public function setCommission(Restaurant $restaurant, string $kind, float $value, User $by): void
    {
        if (! in_array($kind, ['percent', 'taka'], true) || $value < 0 || ($kind === 'percent' && $value > 90)) {
            throw new StockException('The commission is a percent up to 90, or an amount in taka for each order.');
        }

        $restaurant->update(['commission_kind' => $kind, 'commission_value' => $value]);
        $this->activity->record('restaurant.commission', $restaurant->owner, ['kind' => $kind, 'value' => $value], $by);
    }

    /** A suspended restaurant takes no new orders; the ones it already has must still be completed. */
    public function suspend(Restaurant $restaurant, bool $on, User $by): void
    {
        $restaurant->update(['status' => $on ? 'suspended' : 'active']);
        $this->activity->record($on ? 'restaurant.suspended' : 'restaurant.restored', $restaurant->owner, [], $by);
    }

    // ------------------------------------------------------------------ the owner's settings

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws StockException
     */
    public function update(Restaurant $r, array $data, ?UploadedFile $photo = null): Restaurant
    {
        $changes = collect($data)->only(['name', 'description', 'phone', 'address', 'opens_at', 'closes_at', 'delivery_by', 'charge_mode', 'charge_inside', 'charge_outside', 'min_charge', 'eat_there', 'advance_over', 'advance_percent', 'per_km', 'max_km', 'lat', 'lng'])->all();

        foreach (['opens_at', 'closes_at'] as $key) {
            if (isset($changes[$key]) && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $changes[$key])) {
                throw new StockException('Write the time like 10:00.');
            }
        }

        if (isset($changes['advance_percent']) && ((int) $changes['advance_percent'] < 0 || (int) $changes['advance_percent'] > 100)) {
            throw new StockException('The advance is a percent from 0 to 100.');
        }

        // Charging by the kilometre, or stopping at a longest distance, needs to know where the restaurant is.
        foreach (['max_km', 'lat', 'lng'] as $key) {
            if (array_key_exists($key, $changes) && ($changes[$key] === '' || $changes[$key] === null)) {
                $changes[$key] = null;
            }
        }

        $mode = $changes['charge_mode'] ?? $r->charge_mode;
        $max = array_key_exists('max_km', $changes) ? $changes['max_km'] : $r->max_km;
        $hasPlace = (array_key_exists('lat', $changes) ? $changes['lat'] : $r->lat) !== null && (array_key_exists('lng', $changes) ? $changes['lng'] : $r->lng) !== null;

        if (($mode === 'distance' || $max !== null) && ! $hasPlace) {
            throw new StockException('Set where the restaurant is first, so the distance can be worked out.');
        }

        if ($mode === 'distance' && (float) ($changes['per_km'] ?? $r->per_km) <= 0) {
            throw new StockException('Write what you charge for each kilometre.');
        }

        if (array_key_exists('areas', $data)) {
            $changes['areas'] = $this->areaList(array_merge([$r->area], (array) $data['areas']));
        }

        if (array_key_exists('closed_days', $data)) {
            $changes['closed_days'] = collect((array) $data['closed_days'])->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 0 && $d <= 6)->unique()->values()->all();
        }

        if ($photo) {
            $changes['photo_path'] = $this->images->store($photo, 'food');
        }

        $r->update($changes);

        return $r->fresh();
    }

    /** Open or closed, by hand, any time. */
    public function setOpen(Restaurant $r, bool $open): void
    {
        $r->update(['is_open' => $open]);
    }

    // ------------------------------------------------------------------ the menu

    /** @throws StockException */
    public function saveItem(Restaurant $r, array $data, ?UploadedFile $photo = null, ?FoodItem $item = null): FoodItem
    {
        $name = trim((string) ($data['name'] ?? $item?->name ?? ''));
        $price = $data['price'] ?? $item?->price;

        if (mb_strlen($name) < 2) {
            throw new StockException('Give the dish a name.');
        }

        if (! is_numeric($price) || (float) $price <= 0 || (float) $price > 100000) {
            throw new StockException('A price must be more than zero.');
        }

        $fields = [
            'name' => mb_substr($name, 0, 120), 'price' => round((float) $price, 2), 'description' => isset($data['description']) ? mb_substr(trim((string) $data['description']), 0, 300) : $item?->description,
            'cook_minutes' => max(0, min(240, (int) ($data['cook_minutes'] ?? $item?->cook_minutes ?? 15))), 'ready_made' => (bool) ($data['ready_made'] ?? $item?->ready_made ?? false),
        ];

        if ($photo) {
            $fields['photo_path'] = $this->images->store($photo, 'food');
        }

        if ($item) {
            abort_unless($item->restaurant_id === $r->id, 404);
            $item->update($fields);

            return $item->fresh();
        }

        return $r->items()->create($fields + ['available' => true]);
    }

    public function setAvailable(FoodItem $item, bool $on): void
    {
        $item->update(['available' => $on, 'sold_out_on' => $on ? null : $item->sold_out_on]);
    }

    /** "Sold out today": off for the rest of today, back on by itself tomorrow. */
    public function soldOutToday(FoodItem $item, bool $on): void
    {
        $item->update(['sold_out_on' => $on ? self::now()->toDateString() : null]);
    }

    // ------------------------------------------------------------------ what customers see

    /** Is it taking orders now: not suspended, switched open, inside its hours and not on a closed day (it closes by itself, spec ch 17). */
    public function isOpenNow(Restaurant $r): bool
    {
        if ($r->status !== 'active' || ! $r->is_open) {
            return false;
        }

        $now = self::now();

        if (in_array($now->dayOfWeek, (array) $r->closed_days, true)) {
            return false;
        }

        $time = $now->format('H:i');

        // Hours past midnight (open 18:00, close 02:00) run into the next day.
        return $r->opens_at <= $r->closes_at ? ($time >= $r->opens_at && $time < $r->closes_at) : ($time >= $r->opens_at || $time < $r->closes_at);
    }

    public function serves(Restaurant $r, string $area): bool
    {
        return in_array(mb_strtolower(trim($area)), array_map('mb_strtolower', (array) $r->areas), true);
    }

    /**
     * The restaurants for a customer in this area (spec ch 17: location first, then only restaurants connected there). Closed ones are listed after the open ones.
     *
     * @return Collection<int, Restaurant>
     */
    public function near(string $area): Collection
    {
        $area = mb_strtolower(trim($area));

        return Restaurant::where('status', 'active')->get()->filter(fn (Restaurant $r) => $this->serves($r, $area))
            ->sortBy(fn (Restaurant $r) => [$this->isOpenNow($r) ? 0 : 1, $r->name])->values();
    }

    /** What can be ordered right now. */
    public function menu(Restaurant $r): Collection
    {
        return $r->items->filter(fn (FoodItem $i) => $i->isOrderable())->values();
    }

    /** The areas every restaurant can name: kept tidy, no repeats. @return array<int, string> */
    public function areaList(array $areas): array
    {
        return collect($areas)->map(fn ($a) => mb_substr(trim((string) $a), 0, 80))->filter()->unique(fn ($a) => mb_strtolower($a))->values()->all();
    }

    // ------------------------------------------------------------------ money

    /** What the restaurant is paid, by day and by month, and what we keep (counted on delivered orders only). */
    public function sales(Restaurant $r, ?string $month = null): array
    {
        $month ??= self::now()->format('Y-m');
        $today = self::now()->toDateString();
        $delivered = FoodOrder::where('restaurant_id', $r->id)->where('status', 'delivered')->get(['subtotal', 'commission', 'delivered_at']);
        $in = fn (Collection $rows) => ['orders' => $rows->count(), 'sales' => round((float) $rows->sum('subtotal'), 2), 'commission' => round((float) $rows->sum('commission'), 2), 'net' => round((float) $rows->sum('subtotal') - (float) $rows->sum('commission'), 2)];

        return [
            'today' => $in($delivered->filter(fn ($o) => $o->delivered_at->setTimezone('Asia/Dhaka')->toDateString() === $today)),
            'month' => $in($delivered->filter(fn ($o) => $o->delivered_at->setTimezone('Asia/Dhaka')->format('Y-m') === $month)),
            'all' => $in($delivered),
        ];
    }

    public function commissionOn(Restaurant $r, float $subtotal): float
    {
        return round($r->commission_kind === 'percent' ? $subtotal * (float) $r->commission_value / 100 : (float) $r->commission_value, 2);
    }

    public function eatOptions(): array
    {
        return collect(explode(',', (string) Setting::read('food.eat_options', '15,30,45,60')))->map(fn ($n) => (int) trim($n))->filter(fn ($n) => $n > 0)->unique()->sort()->values()->all();
    }
}
