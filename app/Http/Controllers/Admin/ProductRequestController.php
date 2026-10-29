<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Grouped "I was looking for this" requests, most asked first, by sector (spec ch 13, ch 25). Admin only. */
class ProductRequestController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage-customers');

        $groups = ProductRequest::query()
            ->selectRaw("normalized, coalesce(sector, 'medicine') as sector_name, count(*) as total, max(created_at) as last_at, min(query) as sample, sum(case when note is not null then 1 else 0 end) as notes, sum(case when source = ? then 1 else 0 end) as counter, max(case when photo_path is not null then id end) as photo_id", ['counter'])
            // A request from before sectors existed came from the medicine search, so it counts with the medicine ones.
            ->groupBy('normalized', 'sector_name')
            ->orderByDesc('total')
            ->orderByDesc('last_at')
            ->paginate(25)
            ->through(fn ($g) => [
                'normalized' => $g->normalized,
                'sector' => $g->sector_name,
                'sample' => $g->sample,
                'total' => (int) $g->total,
                'notes' => (int) $g->notes,
                'counter' => (int) $g->counter,
                'photo' => $g->photo_id ? route('admin.requests.photo', $g->photo_id) : null,
                'last_at' => Carbon::parse($g->last_at)->toIso8601String(),
            ]);

        return Inertia::render('admin/requests/index', ['groups' => $groups]);
    }

    /** The picture someone attached to a request, for the owner only. */
    public function photo(ProductRequest $productRequest): StreamedResponse
    {
        $this->authorize('manage-customers');
        abort_unless($productRequest->photo_path && Storage::exists($productRequest->photo_path), 404);

        return Storage::response($productRequest->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
