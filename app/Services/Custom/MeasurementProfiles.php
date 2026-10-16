<?php

namespace App\Services\Custom;

use App\Models\CustomOrder;
use App\Models\MeasurementProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;

/**
 * A buyer's saved measurements (spec ch 16.3): as many as they like, each under its own name, each with the date it was taken, an
 * edit, a reminder when it is old, and the list of orders it was used for. A profile used in an order is copied into the order, so
 * editing it later never changes a job that has been agreed.
 */
class MeasurementProfiles
{
    public const MAX = 40;

    public function staleMonths(): int
    {
        return max(1, (int) Setting::read('custom.stale_months', 6));
    }

    /**
     * Keep only the fields this kind asks for, as positive numbers.
     *
     * @return array<string, float>
     *
     * @throws StockException
     */
    public function clean(string $kind, array $values): array
    {
        $allowed = (array) config('custom.fields.'.$kind, []);

        if ($allowed === []) {
            throw new StockException('Choose men, women or wood.');
        }

        $out = [];
        foreach ($allowed as $key => $label) {
            $v = $values[$key] ?? null;

            if ($v === null || $v === '') {
                continue;
            }

            if (! is_numeric($v) || (float) $v <= 0 || (float) $v > 1000) {
                throw new StockException(__('Check the number for :field.', ['field' => __($label)]));
            }
            $out[$key] = round((float) $v, 2);
        }

        if ($out === []) {
            throw new StockException('Write at least one measurement.');
        }

        return $out;
    }

    /** @throws StockException */
    public function save(User $user, string $name, string $kind, string $unit, array $values, ?MeasurementProfile $profile = null): MeasurementProfile
    {
        $name = trim($name);

        if (mb_strlen($name) < 1 || mb_strlen($name) > 60) {
            throw new StockException('Give this person or piece a name.');
        }

        if (! in_array($unit, (array) config('custom.units'), true)) {
            throw new StockException('Choose inch, foot or centimetre.');
        }

        $clean = $this->clean($kind, $values);
        $taken = MeasurementProfile::where('user_id', $user->id)->where('name', $name)->when($profile, fn ($q) => $q->where('id', '!=', $profile->id))->exists();

        if ($taken) {
            throw new StockException('You already have a profile with this name.');
        }

        if (! $profile && MeasurementProfile::where('user_id', $user->id)->count() >= self::MAX) {
            throw new StockException('That is a lot of profiles. Remove one you no longer need.');
        }

        $data = ['name' => $name, 'kind' => $kind, 'unit' => $unit, 'values' => $clean, 'measured_on' => today()];

        if ($profile) {
            $profile->update($data);

            return $profile->fresh();
        }

        return MeasurementProfile::create($data + ['user_id' => $user->id]);
    }

    /** "Still correct": the measurements are checked again today. */
    public function stillCorrect(MeasurementProfile $profile): void
    {
        $profile->update(['measured_on' => today()]);
    }

    public function isStale(MeasurementProfile $profile): bool
    {
        return $profile->measured_on->lt(today()->subMonths($this->staleMonths()));
    }

    /** The orders this profile was used for, newest first. */
    public function orders(MeasurementProfile $profile): Collection
    {
        return CustomOrder::where('buyer_id', $profile->user_id)->latest('id')->limit(100)->get()
            ->filter(fn (CustomOrder $o) => collect($o->items)->contains(fn ($i) => ($i['profile_id'] ?? null) === $profile->id))->values();
    }

    public function present(MeasurementProfile $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'unit' => $p->unit, 'values' => $p->values, 'measured_on' => $p->measured_on->toDateString(),
            'stale' => $this->isStale($p), 'months' => (int) $p->measured_on->diffInMonths(today()),
            'orders' => $this->orders($p)->map(fn (CustomOrder $o) => ['token' => $o->public_token, 'number' => $o->number(), 'status' => $o->status, 'at' => $o->created_at->toIso8601String()])->values(),
        ];
    }
}
