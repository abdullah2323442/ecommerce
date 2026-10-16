<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Medicine;
use App\Models\QuantityStep;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Pricing\PriceWarning;
use App\Services\Sales\PublicCart;
use App\Services\Stock\StockService;
use App\Support\MedicinePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Change a medicine's pack size and printed prices (admin and sub-admin with stock access). A wrong pack size changes old
 * calculations, so it asks twice and keeps a record of who did it (spec 6.8); a new MRP leaves a mark that stays for a month (spec 6.1).
 */
class MedicineController extends Controller
{
    private const PACKING = ['strips_per_box', 'pieces_per_strip'];

    private const PRICES = ['mrp_box', 'mrp_strip', 'mrp_piece'];

    public function __construct(private ActivityLogger $activity) {}

    public function edit(Medicine $medicine): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/stock/medicine', [
            'medicine' => MedicinePresenter::one($medicine->load('company:id,name,logo_url,logo_path')),
            'fields' => $medicine->only([...self::PACKING, ...self::PRICES]),
            'units' => PublicCart::unitsFor($medicine),
            'steps' => QuantityStep::where('medicine_id', $medicine->id)->orderBy('unit')->orderBy('min_qty')->get(['unit', 'min_qty', 'kind', 'value']),
            'history' => ActivityLog::where('subject_type', $medicine->getMorphClass())->where('subject_id', $medicine->id)
                ->whereIn('action', ['medicine.units_changed', 'medicine.mrp_changed'])->latest('id')->limit(10)->get()
                ->map(fn ($l) => ['action' => $l->action, 'changes' => $l->changes, 'at' => $l->created_at->toDateTimeString(), 'by' => User::whereKey($l->actor_id)->value('name')]),
        ]);
    }

    /** Replace the quantity discount steps of this medicine (spec ch 4). An empty list means no discount. */
    public function steps(Request $request, Medicine $medicine): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'steps' => ['present', 'array', 'max:20'],
            'steps.*.unit' => ['required', Rule::in(PublicCart::unitsFor($medicine))],
            'steps.*.min_qty' => ['required', 'integer', 'min:2', 'max:100000'],
            'steps.*.kind' => ['required', Rule::in(['percent', 'taka'])],
            'steps.*.value' => ['required', 'numeric', 'gt:0', 'max:10000000'],
        ]);

        $seen = [];
        foreach ($data['steps'] as $i => $step) {
            $key = $step['unit'].':'.$step['min_qty'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["steps.{$i}.min_qty" => __('Two steps start at the same quantity.')]);
            }
            $seen[$key] = true;

            $mrp = (float) match ($step['unit']) {
                'box' => $medicine->mrp_box, 'strip' => $medicine->mrp_strip, default => $medicine->mrp_piece,
            };
            $off = $step['kind'] === 'percent' ? (float) $step['value'] : ($mrp > 0 ? (float) $step['value'] / $mrp * 100 : 100);
            if ($off >= 90) {
                throw ValidationException::withMessages(["steps.{$i}.value" => __('That takes off 90 percent or more of the price. Check it.')]);
            }
        }

        DB::transaction(function () use ($medicine, $data) {
            QuantityStep::where('medicine_id', $medicine->id)->delete();
            foreach ($data['steps'] as $step) {
                QuantityStep::create(['medicine_id' => $medicine->id] + $step);
            }
        });

        $this->activity->record('medicine.steps_changed', $medicine, ['steps' => count($data['steps'])], $request->user());

        return back()->with('status', __('Saved.'));
    }

    public function update(Request $request, Medicine $medicine): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'strips_per_box' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'pieces_per_strip' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'mrp_box' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'mrp_strip' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'mrp_piece' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'confirm_units' => ['sometimes', 'boolean'],
            'confirm_price' => ['sometimes', 'boolean'],
        ]);

        $packingBefore = $medicine->only(self::PACKING);
        $packingAfter = array_intersect_key($data, array_flip(self::PACKING));
        $packingChanged = array_diff_assoc(array_map('strval', $packingAfter), array_map('strval', $packingBefore)) !== [];

        if ($packingChanged && ! ($data['confirm_units'] ?? false)) {
            throw ValidationException::withMessages(['strips_per_box' => __('This will change old calculations. Confirm to go on.')]);
        }

        $unitMrpBefore = StockService::unitMrp($medicine);
        $medicine->fill(array_intersect_key($data, array_flip([...self::PACKING, ...self::PRICES])));
        $dirty = $medicine->getDirty();

        // A price far below the one before, or zero, must be confirmed (spec ch 04: 50 instead of 500).
        if (! ($data['confirm_price'] ?? false)) {
            $warn = app(PriceWarning::class);
            foreach (self::PRICES as $field) {
                if (array_key_exists($field, $dirty) && ($drop = $warn->drop($medicine->getOriginal($field) === null ? null : (float) $medicine->getOriginal($field), $medicine->{$field} === null ? null : (float) $medicine->{$field})) !== null) {
                    throw ValidationException::withMessages(['price_warning' => $warn->message($drop)]);
                }
            }
        }

        if ($dirty === []) {
            return back()->with('status', __('Nothing changed.'));
        }

        $unitMrpAfter = StockService::unitMrp($medicine);
        if (array_intersect(array_keys($dirty), self::PRICES) !== [] && abs($unitMrpAfter - $unitMrpBefore) > 0.004) {
            $medicine->mrp_changed_at = now();
            $medicine->mrp_before = $unitMrpBefore;
        }
        $medicine->save();

        if ($packingChanged) {
            $this->activity->record('medicine.units_changed', $medicine, ['before' => $packingBefore, 'after' => $packingAfter], $request->user());
        }
        if (array_intersect(array_keys($dirty), self::PRICES) !== []) {
            $this->activity->record('medicine.mrp_changed', $medicine, ['before' => $unitMrpBefore, 'after' => $unitMrpAfter], $request->user());
        }

        return back()->with('status', __('Saved.'));
    }
}
