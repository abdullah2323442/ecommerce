<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weights and mandatory delivery charges for medicines, in bulk (spec ch 20). A weight makes the courier charge right; without
 * one on every product in an order the flat area charge is used. Adding weights is the reminder the dashboard keeps showing.
 */
class WeightController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-stock');
        $q = trim((string) $request->query('q', ''));
        $missing = $request->boolean('missing');

        $rows = Medicine::query()->with('company:id,name')->where('is_active', true)
            ->when($missing, fn ($w) => $w->whereNull('weight_g'))
            ->when($q !== '', function ($w) use ($q) {
                $like = addcslashes($q, '%_\\').'%';
                $w->where(fn ($x) => $x->where('brand_name', 'like', $like)->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->orderBy('brand_name')->orderBy('strength')->paginate(30)->withQueryString()
            ->through(fn (Medicine $m) => [
                'id' => $m->id, 'name' => trim($m->brand_name.' '.$m->strength), 'company' => $m->company?->name, 'packaging' => $m->packaging_type,
                'weight_g' => $m->weight_g, 'fixed_in' => $m->delivery_fixed_in === null ? null : (float) $m->delivery_fixed_in, 'fixed_out' => $m->delivery_fixed_out === null ? null : (float) $m->delivery_fixed_out,
            ]);

        return Inertia::render('admin/weights', [
            'rows' => $rows,
            'filters' => ['q' => $q, 'missing' => $missing],
            'without' => Medicine::where('is_active', true)->whereNull('weight_g')->count(),
        ]);
    }

    public function update(Request $request, Medicine $medicine): RedirectResponse
    {
        $this->authorize('manage-stock');
        $data = $request->validate([
            'weight_g' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'fixed_in' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'fixed_out' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        // When both are there, the mandatory charge is the highest layer, so the price outside should not be lower than inside.
        if (isset($data['fixed_in'], $data['fixed_out']) && (float) $data['fixed_out'] < (float) $data['fixed_in']) {
            return back()->withErrors(['fixed_out' => __('The charge outside cannot be less than inside.')]);
        }

        $medicine->forceFill(['weight_g' => $data['weight_g'] ?? null, 'delivery_fixed_in' => $data['fixed_in'] ?? null, 'delivery_fixed_out' => $data['fixed_out'] ?? null])->save();

        return back()->with('status', __('Saved.'));
    }
}
