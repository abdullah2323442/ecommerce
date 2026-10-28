<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Services\Marketing\Affiliates;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's side of the affiliate programme (spec ch 27): open an affiliate, set their share, see what they earned, pay them. */
class AffiliateController extends Controller
{
    public function __construct(private Affiliates $affiliates) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-customers');
        $rows = $this->affiliates->overview();
        $open = $request->query('affiliate') ? $rows->firstWhere('id', (int) $request->query('affiliate')) : null;

        return Inertia::render('admin/affiliates', [
            'affiliates' => $rows->values()->all(),
            'chosen' => $open ? $this->affiliates->statement(Affiliate::findOrFail($open['id'])) : null,
            'days' => $this->affiliates->days(),
            'totals' => ['owed' => round((float) $rows->sum('owed'), 2), 'paid' => round((float) $rows->sum('paid'), 2), 'clicks' => (int) $rows->sum('clicks')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'], 'percent' => ['required', 'numeric', 'gt:0', 'max:50'],
            'code' => ['nullable', 'string', 'max:24'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $a = $this->affiliates->create($data, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['affiliate' => __($e->getMessage())])->withInput();
        }

        return to_route('admin.affiliates.index', ['affiliate' => $a->id])->with('status', __('The affiliate is added. Give them their link.'));
    }

    public function update(Request $request, Affiliate $affiliate): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['percent' => ['sometimes', 'numeric', 'gt:0', 'max:50'], 'active' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:200']]);

        try {
            $this->affiliates->update($affiliate, $data, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['affiliate' => __($e->getMessage())]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function pay(Request $request, Affiliate $affiliate): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['method' => ['required', 'string', 'max:12'], 'note' => ['nullable', 'string', 'max:200']]);

        try {
            $this->affiliates->pay($affiliate, $data['method'], $data['note'] ?? null, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['pay' => __($e->getMessage())]);
        }

        return back()->with('status', __('The payment is written down.'));
    }
}
