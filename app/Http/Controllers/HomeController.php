<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Medicine;
use App\Models\Sector;
use App\Models\Testimonial;
use App\Services\Market\Marketplace;
use App\Support\MedicinePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public home page. Everything on it is real: counts, forms and featured packs are live queries. It never mentions paikari or wholesale (golden rule G1), and it
 * makes no promise about ordering or delivery, which do not exist yet.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, Marketplace $market): Response
    {
        // The pharmacy: counts, real packs from the biggest companies (MRP only, through the presenter), the companies, and the forms.
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
                'companies' => $top->take(10)
                    ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'logo' => $c->logo, 'count' => $c->medicines_count])
                    ->values()->all(),
                'featured' => MedicinePresenter::many(
                    $top->map(fn (Company $c) => Medicine::active()
                        ->where('company_id', $c->id)
                        ->whereNotNull('image_path')
                        ->where('mrp_piece', '>', 0)
                        ->with('company:id,name,logo_url,logo_path')
                        ->orderBy('id')
                        ->first())
                        ->filter()
                        ->take(6)
                        ->values()
                ),
                'forms' => collect(FormController::forms())->take(8)
                    ->map(fn ($f, $slug) => ['slug' => $slug, 'name' => $f['name'], 'count' => $f['count']])->values()->all(),
            ];
        });

        // Every kind of business with something on sale: its banner and four products, priced for this viewer.
        $bn = app()->getLocale() === 'bn';
        $all = $market->visible($request->user());
        $rows = $all->groupBy(fn ($r) => $r['listing']->sector->slug);
        $aisles = Sector::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (Sector $s) => $rows->has($s->slug))
            ->map(fn (Sector $s) => [
                'slug' => $s->slug,
                'name' => $bn && $s->name_bn ? $s->name_bn : $s->name,
                'line' => $bn && $s->tagline_bn ? $s->tagline_bn : $s->tagline,
                'image' => $s->hero_path ? route('market.hero', ['slug' => $s->slug, 'v' => substr(md5($s->hero_path), 0, 8)]) : null,
                'count' => $rows[$s->slug]->count(),
                'items' => $rows[$s->slug]->take(4)->map(fn ($r) => [
                    'id' => $r['listing']->id, 'title' => $r['listing']->title, 'blurb' => Str::limit(trim(strip_tags((string) $r['listing']->description)), 130), 'price' => $r['price'], 'unit' => $s->unit,
                    'photo' => $r['listing']->photo_path ? route('market.photo', ['listing' => $r['listing']->id, 'v' => substr(md5($r['listing']->photo_path.$r['listing']->updated_at), 0, 8)]) : null,
                ])->values()->all(),
            ])->values()->all();

        // Shop by budget: the same ranges the market offers, each with how many products fall in it and three of them to look at.
        $bands = collect($market->ranges($all->pluck('price')))->map(function (array $r) use ($all) {
            $in = $all->filter(fn ($x) => $x['price'] >= $r['from'] && ($r['to'] === null || $x['price'] < $r['to']));

            return $r + [
                'count' => $in->count(),
                'photos' => $in->filter(fn ($x) => $x['listing']->photo_path)->sortByDesc('price')->take(3)->map(fn ($x) => route('market.photo', ['listing' => $x['listing']->id, 'v' => substr(md5($x['listing']->photo_path.$x['listing']->updated_at), 0, 8)]))->values()->all(),
            ];
        })->values()->all();

        // What buyers say: the owner's words, in the reader's language when there is a Bangla version.
        $voices = Cache::remember('home.testimonials', 600, fn () => Testimonial::where('is_active', true)->orderBy('sort')->orderBy('id')->limit(9)->get(['name', 'place', 'quote', 'quote_bn', 'rating'])->all());
        $testimonials = collect($voices)->map(fn (Testimonial $v) => [
            'name' => $v->name, 'place' => $v->place, 'rating' => $v->rating, 'quote' => $bn && $v->quote_bn ? $v->quote_bn : $v->quote,
        ])->values()->all();

        return Inertia::render('home', $data + ['aisles' => $aisles, 'bands' => $bands, 'testimonials' => $testimonials]);
    }
}
