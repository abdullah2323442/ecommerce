<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\Sector;
use App\Services\Audit\ActivityLogger;
use App\Services\Market\Catalog;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The central product catalog (spec ch 13): add products, and answer the ones sellers ask for. */
class CatalogController extends Controller
{
    public function __construct(private Catalog $catalog, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-customers');
        $row = fn (CatalogProduct $p) => [
            'id' => $p->id, 'name' => $p->name, 'name_bn' => $p->name_bn, 'sector' => $p->sector->name, 'status' => $p->status, 'asked_by' => $p->requester?->business_name ?: $p->requester?->name,
            'at' => $p->created_at->toIso8601String(), 'sellers' => $p->listings()->where('status', 'published')->count(),
        ];

        return Inertia::render('admin/catalog', [
            'waiting' => CatalogProduct::where('status', 'pending')->with('sector:id,name', 'requester:id,name,business_name')->oldest('id')->get()->map($row),
            'products' => CatalogProduct::where('status', 'approved')->with('sector:id,name', 'requester:id,name,business_name')->orderBy('sector_id')->orderBy('name')->limit(300)->get()->map($row),
            'sectors' => Sector::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['sector_id' => ['required', 'integer', 'exists:sectors,id'], 'name' => ['required', 'string', 'min:2', 'max:150'], 'name_bn' => ['nullable', 'string', 'max:150']]);

        try {
            $product = $this->catalog->add(Sector::findOrFail($data['sector_id']), $data['name'], $data['name_bn'] ?? null, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['name' => __($e->getMessage())]);
        }
        $this->activity->record('catalog.added', null, ['product' => $product->id], $request->user());

        return back()->with('status', __('Added to the catalog.'));
    }

    public function approve(Request $request, CatalogProduct $product): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['name' => ['nullable', 'string', 'max:150']]);

        try {
            $this->catalog->approve($product->load('sector', 'requester'), $request->user(), $data['name'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['name' => __($e->getMessage())]);
        }
        $this->activity->record('catalog.approved', null, ['product' => $product->id], $request->user());

        return back()->with('status', __('Added to the catalog.'));
    }

    public function reject(Request $request, CatalogProduct $product): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $this->catalog->reject($product->load('requester'), $request->user(), $data['note']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['note' => __($e->getMessage())]);
        }
        $this->activity->record('catalog.rejected', null, ['product' => $product->id], $request->user());

        return back()->with('status', __('Turned down. The seller is told.'));
    }
}
