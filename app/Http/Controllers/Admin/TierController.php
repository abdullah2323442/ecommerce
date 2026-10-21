<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyRate;
use App\Models\MedicineRate;
use App\Models\Tier;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\Bin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Unlimited tiers; Siam names them and sets the rate (spec PRC-1). A rate change reprices the whole tier. */
class TierController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/tiers/index', [
            'tiers' => Tier::query()->withCount('users')->orderBy('name')->get(['id', 'name', 'discount_percent', 'upgrade_target']),
            // Selling rates by company and by medicine: they sit between a customer's own rate and the tier.
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'companyRates' => CompanyRate::query()->join('companies', 'companies.id', '=', 'company_rates.company_id')->orderBy('companies.name')
                ->get(['company_rates.company_id as id', 'companies.name', 'company_rates.discount_percent as percent']),
            'medicineRates' => MedicineRate::query()->join('medicines', 'medicines.id', '=', 'medicine_rates.medicine_id')->orderBy('medicines.brand_name')
                ->get(['medicine_rates.medicine_id as id', 'medicines.brand_name', 'medicines.strength', 'medicine_rates.discount_percent as percent', 'medicine_rates.fixed_price']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-customers');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('tiers', 'name')],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'upgrade_target' => ['nullable', 'numeric', 'min:1', 'max:1000000000'],
            'upgrade_target' => ['nullable', 'numeric', 'min:1', 'max:1000000000'],
        ]);

        $tier = Tier::create($data);
        $this->activity->record('tier.created', $tier, $data, $request->user());

        return back()->with('status', __('Tier :name created.', ['name' => $tier->name]));
    }

    public function update(Request $request, Tier $tier): RedirectResponse
    {
        $this->authorize('manage-customers');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('tiers', 'name')->ignore($tier->id)],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'upgrade_target' => ['nullable', 'numeric', 'min:1', 'max:1000000000'],
            'upgrade_target' => ['nullable', 'numeric', 'min:1', 'max:1000000000'],
        ]);

        $before = ['name' => $tier->name, 'discount_percent' => (string) $tier->discount_percent, 'upgrade_target' => $tier->upgrade_target];
        $tier->update($data);

        $this->activity->record('tier.updated', $tier, ['before' => $before, 'after' => $data], $request->user());

        return back()->with('status', __('Tier :name updated. Every customer in it is repriced.', ['name' => $tier->name]));
    }

    /** A tier with customers cannot be deleted; move them first. */
    public function destroy(Request $request, Tier $tier): RedirectResponse
    {
        $this->authorize('manage-customers');

        if ($tier->users()->exists()) {
            return back()->withErrors(['tier' => __('Move customers to another tier before deleting this tier.')]);
        }

        $this->activity->record('tier.deleted', $tier, ['name' => $tier->name], $request->user());
        app(Bin::class)->keep($tier, $request->user());
        $tier->delete();

        return back()->with('status', __('Tier deleted.'));
    }
}
