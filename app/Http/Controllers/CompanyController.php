<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\MedicinePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Browse by company (spec ch 29-A: shop owners think "what does Square have?"). Open to everyone, MRP only. */
class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $companies = Company::query()
            ->where('is_active', true)
            ->whereHas('medicines', fn ($m) => $m->active())
            ->withCount(['medicines as medicines_count' => fn ($m) => $m->active()])
            ->when($q !== '', fn ($c) => $c->where('name', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')
            ->paginate(48)
            ->withQueryString()
            ->through(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'logo' => $c->logo, 'count' => $c->medicines_count]);

        return Inertia::render('companies/index', ['companies' => $companies, 'query' => $q]);
    }

    public function show(Company $company): Response
    {
        abort_unless($company->is_active, 404);

        $medicines = $company->medicines()->active()->with('company:id,name,logo_url,logo_path')
            ->orderBy('brand_name')->orderBy('strength')
            ->paginate(40)
            ->withQueryString()
            ->through(fn ($m) => MedicinePresenter::one($m));

        return Inertia::render('companies/show', [
            'company' => ['id' => $company->id, 'name' => $company->name, 'logo' => $company->logo],
            'medicines' => $medicines,
        ]);
    }
}
