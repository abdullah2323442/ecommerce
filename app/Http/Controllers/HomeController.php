<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Medicine;
use App\Support\MedicinePresenter;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public home page. Everything on it is real: counts, forms and featured packs are live queries. It never mentions paikari or wholesale (golden rule G1), and it
 * makes no promise about ordering or delivery, which do not exist yet.
 */
class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $data = Cache::remember('home.data', 600, function () {
            $top = Company::query()
                ->whereNotNull('logo_path')
                ->withCount(['medicines as medicines_count' => fn ($q) => $q->active()])
                ->orderByDesc('medicines_count')
                ->limit(12)
                ->get(['id', 'name', 'logo_url', 'logo_path']);

            return [
                'stats' => [
                    'medicines' => Medicine::active()->count(),
                    'companies' => Company::whereHas('medicines', fn ($q) => $q->active())->count(),
                    'generics' => Medicine::active()->whereNotNull('generic_name')->distinct()->count('generic_name'),
                ],
                'companies' => $top
                    ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'logo' => $c->logo, 'count' => $c->medicines_count])
                    ->all(),
                // One medicine with a real pack photo from each of the biggest companies. MRP only, via the presenter.
                'featured' => MedicinePresenter::many(
                    $top->map(fn (Company $c) => Medicine::active()
                        ->where('company_id', $c->id)
                        ->whereNotNull('image_path')
                        ->where('mrp_piece', '>', 0)
                        ->with('company:id,name,logo_url,logo_path')
                        ->orderBy('id')
                        ->first())
                        ->filter()
                        ->take(10)
                        ->values()
                ),
                'forms' => collect(FormController::forms())->take(8)
                    ->map(fn ($f, $slug) => ['slug' => $slug, 'name' => $f['name'], 'count' => $f['count']])->values()->all(),
            ];
        });

        return Inertia::render('home', $data);
    }
}
