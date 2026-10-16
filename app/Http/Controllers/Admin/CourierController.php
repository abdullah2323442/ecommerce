<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\Bin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's courier list (spec ch 20): any number of couriers, each with a charge per zone for the first kilo and for every
 * kilo after it, and the share the courier takes to collect cash. Typed by hand; there is no courier integration. Rates go
 * out of date quietly, so the screen asks every three months whether they are still right.
 */
class CourierController extends Controller
{
    public const CHECK_EVERY_DAYS = 90;

    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');
        $checked = Setting::read('delivery.rates_checked_on');

        return Inertia::render('admin/couriers', [
            'couriers' => Courier::with('rates')->orderBy('name')->get()->map(fn (Courier $c) => [
                'id' => $c->id, 'name' => $c->name, 'provider' => $c->provider, 'cod_percent' => (float) $c->cod_percent, 'is_active' => $c->is_active,
                'rates' => collect(Courier::ZONES)->mapWithKeys(fn ($z) => [$z => ($r = $c->rates->firstWhere('zone', $z)) ? ['first_kg' => (float) $r->first_kg, 'extra_kg' => (float) $r->extra_kg] : null])->all(),
            ]),
            'checked_on' => $checked,
            'providers' => app(\App\Services\Couriers\CourierBooking::class)->providers(),
            'stale' => $this->stale(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new Courier);
    }

    public function update(Request $request, Courier $courier): RedirectResponse
    {
        return $this->save($request, $courier);
    }

    public function destroy(Request $request, Courier $courier): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $this->activity->record('courier.deleted', $courier, ['name' => $courier->name], $request->user());
        app(Bin::class)->keep($courier, $request->user());
        $courier->delete();

        return back()->with('status', __('Courier removed.'));
    }

    /** "Yes, the rates are still right." */
    public function confirm(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        Setting::write('delivery.rates_checked_on', today()->toDateString());
        $this->activity->record('courier.rates_confirmed', null, [], $request->user());

        return back()->with('status', __('Thank you. We will ask again in three months.'));
    }

    /** True when there are couriers and nobody has looked at their rates for three months (spec ch 20: stale rates lose money quietly). */
    public static function isStale(): bool
    {
        return (new self(app(ActivityLogger::class)))->stale();
    }

    private function stale(): bool
    {
        if (! Courier::where('is_active', true)->exists()) {
            return false;
        }

        $checked = Setting::read('delivery.rates_checked_on');

        return $checked === null || Carbon::parse($checked)->lt(today()->subDays(self::CHECK_EVERY_DAYS));
    }

    private function save(Request $request, Courier $courier): RedirectResponse
    {
        $this->authorize('manage-accounts');

        $rules = ['name' => ['required', 'string', 'max:80'], 'cod_percent' => ['required', 'numeric', 'min:0', 'max:20'], 'is_active' => ['required', 'boolean'], 'rates' => ['required', 'array'],
            // Tie this courier to a company connected in Admin > Connections, to book parcels with them.
            'provider' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_column(app(\App\Services\Couriers\CourierBooking::class)->providers(), 'key'))]];
        foreach (Courier::ZONES as $zone) {
            $rules["rates.$zone"] = ['nullable', 'array'];
            $rules["rates.$zone.first_kg"] = ["required_with:rates.$zone.extra_kg", 'nullable', 'numeric', 'min:0', 'max:100000'];
            $rules["rates.$zone.extra_kg"] = ["required_with:rates.$zone.first_kg", 'nullable', 'numeric', 'min:0', 'max:100000'];
        }
        $data = $request->validate($rules);

        DB::transaction(function () use ($courier, $data, $request) {
            $courier->fill(['name' => trim($data['name']), 'provider' => $data['provider'] ?? null, 'cod_percent' => $data['cod_percent'], 'is_active' => $data['is_active']])->save();
            $courier->rates()->delete();

            foreach (Courier::ZONES as $zone) {
                $r = $data['rates'][$zone] ?? null;
                // A zone left empty means this courier does not go there.
                if ($r && isset($r['first_kg'], $r['extra_kg'])) {
                    $courier->rates()->create(['zone' => $zone, 'first_kg' => $r['first_kg'], 'extra_kg' => $r['extra_kg']]);
                }
            }

            Setting::write('delivery.rates_checked_on', today()->toDateString());
            $this->activity->record($courier->wasRecentlyCreated ? 'courier.created' : 'courier.updated', $courier, ['name' => $courier->name, 'rates' => $data['rates']], $request->user());
        });

        return back()->with('status', __('Courier saved.'));
    }
}
