<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyRate;
use App\Models\Medicine;
use App\Models\MedicineRate;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What customers pay, set for a whole company or for one medicine (spec ch 04, layers 2 and 3). A customer's own rate still
 * beats these; these beat the customer's tier. Empty removes the rate.
 */
class SellingRateController extends Controller
{
    public function __construct(private ActivityLogger $activity, private StockService $stock) {}

    /** A percent off the printed price for every medicine of one company. */
    public function company(Request $request, Company $company): RedirectResponse
    {
        $this->authorize('manage-customers');

        $data = $request->validate(['percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'force_loss' => ['sometimes', 'boolean']]);

        if ($data['percent'] === null) {
            CompanyRate::where('company_id', $company->id)->delete();
            $this->activity->record('pricing.company_rate_removed', $company, [], $request->user());

            return back()->with('status', __('Company rate removed.'));
        }

        // LOS-1 for a whole company: blocked when any medicine in stock would sell below what it cost, unless the owner confirms.
        $loses = Medicine::where('company_id', $company->id)->whereHas('lots', fn ($q) => $q->where('units_remaining', '>', 0))->get()
            ->contains(fn (Medicine $m) => $this->stock->rateWouldLose($m, (float) $data['percent'], null));
        if ($loses && ! ($data['force_loss'] ?? false)) {
            throw ValidationException::withMessages(['loss' => __('This rate is below what some of this company\'s medicines cost. Raise it, or confirm to sell at a loss.')]);
        }

        CompanyRate::updateOrCreate(['company_id' => $company->id], ['discount_percent' => $data['percent']]);
        $this->activity->record('pricing.company_rate_set', $company, ['percent' => $data['percent'], 'loss_forced' => $loses], $request->user());

        return back()->with('status', __('Company rate saved.'));
    }

    /** A percent off, or a taka price for one piece, for one medicine. It beats the company rate. */
    public function medicine(Request $request, Medicine $medicine): RedirectResponse
    {
        $this->authorize('manage-customers');

        $data = $request->validate([
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_price' => ['nullable', 'numeric', 'gt:0'],
            'force_loss' => ['sometimes', 'boolean'],
        ]);

        $percent = $data['percent'] ?? null;
        $fixed = $percent === null ? ($data['fixed_price'] ?? null) : null;

        if ($percent === null && $fixed === null) {
            MedicineRate::where('medicine_id', $medicine->id)->delete();
            $this->activity->record('pricing.medicine_rate_removed', $medicine, [], $request->user());

            return back()->with('status', __('Medicine rate removed.'));
        }

        // A taka price is for ONE PIECE; at or above the printed piece price it gives no discount at all.
        if ($fixed !== null && (float) $medicine->mrp_piece > 0 && (float) $fixed >= (float) $medicine->mrp_piece) {
            throw ValidationException::withMessages(['fixed_price' => __('A taka price is for one piece, and this medicine is printed at :price a piece. Type a price below that (for a box, divide by the pieces in it), or use a percent.', ['price' => number_format((float) $medicine->mrp_piece, 2)])]);
        }

        $loses = $this->stock->rateWouldLose($medicine, $percent === null ? null : (float) $percent, $fixed === null ? null : (float) $fixed);
        if ($loses && ! ($data['force_loss'] ?? false)) {
            throw ValidationException::withMessages(['loss' => __('This rate is below what this medicine cost. Raise it, or confirm to sell at a loss.')]);
        }

        MedicineRate::updateOrCreate(['medicine_id' => $medicine->id], ['discount_percent' => $percent, 'fixed_price' => $fixed]);
        $this->activity->record('pricing.medicine_rate_set', $medicine, ['percent' => $percent, 'fixed_price' => $fixed, 'loss_forced' => $loses], $request->user());

        return back()->with('status', __('Medicine rate saved.'));
    }
}
