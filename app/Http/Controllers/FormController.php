<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Support\MedicinePresenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Browse medicines by form (tablet, capsule, syrup…). Open to everyone, MRP only. */
class FormController extends Controller
{
    /** @return array<string, array{name: string, count: int}> slug => form, for every form that has active medicines */
    public static function forms(): array
    {
        return Cache::remember('forms.index', 600, function () {
            return Medicine::query()->active()->whereNotNull('form')
                ->selectRaw('form, count(*) as total')->groupBy('form')->orderByDesc('total')->get()
                ->mapWithKeys(fn ($r) => [Str::slug($r->form) => ['name' => $r->form, 'count' => (int) $r->total]])
                ->all();
        });
    }

    public function show(string $slug): Response
    {
        $form = self::forms()[$slug] ?? abort(404);

        $medicines = Medicine::query()->active()->where('form', $form['name'])
            ->with('company:id,name,logo_url,logo_path')
            ->orderBy('brand_name')->orderBy('strength')
            ->paginate(30)
            ->withQueryString()
            ->through(fn ($m) => MedicinePresenter::one($m));

        return Inertia::render('forms/show', [
            'form' => ['name' => $form['name'], 'slug' => $slug],
            'medicines' => $medicines,
            'forms' => collect(self::forms())->map(fn ($f, $s) => ['slug' => $s, 'name' => $f['name'], 'count' => $f['count']])->values()->take(10)->all(),
        ]);
    }
}
