<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What customers look for and do (spec ch 27: behaviour data from day one). It answers: what do people search for and not
 * find, which medicines are looked at but never put in a cart, and how many visitors get from looking to ordering.
 */
class BehaviorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view-reports');
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $since = now()->subDays($days);

        $events = fn () => DB::table('behavior_events')->where('created_at', '>=', $since);

        $searches = $events()->where('type', 'search')->whereNotNull('term')
            ->groupBy(DB::raw('lower(term)'))
            ->orderByDesc(DB::raw('count(*)'))->limit(25)
            ->get([DB::raw('lower(term) as term'), DB::raw('count(*) as times'), DB::raw("min(json_extract(meta, '\$.found')) as found")])
            ->map(fn ($r) => ['term' => $r->term, 'times' => (int) $r->times, 'found' => $r->found === null ? null : (int) $r->found]);

        $viewed = $events()->where('type', 'view')->whereNotNull('subject_id')->groupBy('subject_id')->orderByDesc(DB::raw('count(*)'))->limit(30)
            ->pluck(DB::raw('count(*)'), 'subject_id');
        $carts = $events()->whereIn('type', ['cart', 'preorder'])->whereIn('subject_id', $viewed->keys())->groupBy('subject_id')->pluck(DB::raw('count(*)'), 'subject_id');
        $names = DB::table('medicines')->whereIn('id', $viewed->keys())->get(['id', 'brand_name', 'strength'])->keyBy('id');

        $medicines = $viewed->map(fn ($views, $id) => [
            'id' => (int) $id, 'name' => trim(($names[$id]->brand_name ?? '').' '.($names[$id]->strength ?? '')), 'views' => (int) $views, 'carts' => (int) ($carts[$id] ?? 0),
        ])->values();

        $visitors = fn (string $type) => (int) $events()->where('type', $type)->distinct()->count('visitor');

        return Inertia::render('admin/behavior', [
            'days' => $days,
            'searches' => $searches,
            'medicines' => $medicines,
            'funnel' => ['search' => $visitors('search'), 'view' => $visitors('view'), 'cart' => $visitors('cart'), 'checkout' => $visitors('checkout')],
        ]);
    }
}
