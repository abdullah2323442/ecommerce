<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductRequest;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Grouped "I was looking for this" requests, most asked first (spec ch 13, ch 25). Admin only. */
class ProductRequestController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage-customers');

        $groups = ProductRequest::query()
            ->selectRaw('normalized, count(*) as total, max(created_at) as last_at, min(query) as sample, sum(case when note is not null then 1 else 0 end) as notes')
            ->groupBy('normalized')
            ->orderByDesc('total')
            ->orderByDesc('last_at')
            ->paginate(25)
            ->through(fn ($g) => [
                'normalized' => $g->normalized,
                'sample' => $g->sample,
                'total' => (int) $g->total,
                'notes' => (int) $g->notes,
                'last_at' => Carbon::parse($g->last_at)->toIso8601String(),
            ]);

        return Inertia::render('admin/requests/index', ['groups' => $groups]);
    }
}
