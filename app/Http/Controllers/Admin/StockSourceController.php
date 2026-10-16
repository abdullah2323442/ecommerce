<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyPurchaseRate;
use App\Models\Medicine;
use App\Models\MedicinePurchaseRate;
use App\Models\StockSource;
use App\Services\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Sources and the three-layer purchase rate (spec STK-1, STK-2): source default, then company, then medicine. */
class StockSourceController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/stock/sources', [
            'sources' => StockSource::orderBy('name')->get(['id', 'name', 'type', 'default_percent', 'is_active']),
            'types' => StockSource::TYPES,
            'companyRates' => CompanyPurchaseRate::join('companies', 'companies.id', '=', 'company_purchase_rates.company_id')
                ->orderBy('companies.name')->get(['company_purchase_rates.company_id', 'companies.name', 'company_purchase_rates.percent']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('stock_sources', 'name')],
            'type' => ['required', Rule::in(StockSource::TYPES)],
            'default_percent' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
        ]);

        $source = StockSource::create($data);
        $this->activity->record('stock.source_created', $source, $data, $request->user());

        return back()->with('status', __('Source :name added.', ['name' => $source->name]));
    }

    public function update(Request $request, StockSource $source): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('stock_sources', 'name')->ignore($source->id)],
            'type' => ['required', Rule::in(StockSource::TYPES)],
            'default_percent' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
            'is_active' => ['required', 'boolean'],
        ]);

        $before = $source->only(['name', 'type', 'default_percent', 'is_active']);
        $source->update($data);
        $this->activity->record('stock.source_updated', $source, ['before' => $before, 'after' => $data], $request->user());

        return back()->with('status', __('Source :name updated.', ['name' => $source->name]));
    }

    /** One % for every product of a company (the middle layer). Empty removes it. */
    public function companyRate(Request $request, Company $company): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate(['percent' => ['nullable', 'numeric', 'min:0', 'max:99.999']]);

        if ($data['percent'] === null) {
            CompanyPurchaseRate::where('company_id', $company->id)->delete();
        } else {
            CompanyPurchaseRate::updateOrCreate(['company_id' => $company->id], ['percent' => $data['percent']]);
        }

        $this->activity->record('stock.company_rate', $company, $data, $request->user());

        return back()->with('status', __('Company purchase rate saved.'));
    }

    /** A % typed for one medicine (the top layer). Empty removes it. */
    public function medicineRate(Request $request, Medicine $medicine): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate(['percent' => ['nullable', 'numeric', 'min:0', 'max:99.999']]);

        if ($data['percent'] === null) {
            MedicinePurchaseRate::where('medicine_id', $medicine->id)->delete();
        } else {
            MedicinePurchaseRate::updateOrCreate(['medicine_id' => $medicine->id], ['percent' => $data['percent']]);
        }

        $this->activity->record('stock.medicine_rate', $medicine, $data, $request->user());

        return back()->with('status', __('Medicine purchase rate saved.'));
    }
}
