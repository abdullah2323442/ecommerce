<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Medicine;
use App\Models\MpoBid;
use App\Models\MpoListing;
use App\Models\Notice;
use App\Services\Mpo\MpoBidding;
use App\Services\Mpo\MpoService;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The MPO's own screen (spec ch 10). They see only their own companies, products and listings. They cannot set a price, decide who
 * sees a product, or see another MPO or any buyer: the orders they see are placed "from our account" and carry no buyer.
 */
class MpoPortalController extends Controller
{
    public function __construct(private MpoService $mpos, private MpoBidding $bidding) {}

    public function index(Request $request): Response
    {
        $mpo = $request->user();

        return Inertia::render('mpo/index', [
            'companies' => $mpo->mpoCompanies()->orderBy('name')->get(['companies.id', 'companies.name']),
            'chosen' => $mpo->mpoProducts()->with('company:id,name')->orderBy('brand_name')->get()->map(fn ($m) => ['id' => $m->id, 'name' => trim($m->brand_name.' '.$m->strength), 'company' => $m->company?->name]),
            'listings' => MpoListing::where('user_id', $mpo->id)->with('medicine:id,brand_name,strength')->latest('id')->limit(60)->get()->map(fn (MpoListing $l) => [
                'id' => $l->id, 'medicine_id' => $l->medicine_id, 'name' => trim($l->medicine->brand_name.' '.$l->medicine->strength), 'status' => $l->status, 'stock_status' => $l->stock_status,
                'arrive_days' => $l->arrive_days, 'quantity' => $l->quantity, 'bonus_buy' => $l->bonus_buy, 'bonus_free' => $l->bonus_free, 'review_note' => $l->review_note,
                'bids' => $l->status === 'approved' ? $this->bidding->bidsForMpo($l) : [],
                'percent' => $l->status === 'approved' && $l->priceIn('wholesale') !== null ? $this->bidding->currentPercent($l) : null,
            ]),
            'notices' => Notice::where('user_id', $mpo->id)->whereNull('read_at')->latest('id')->limit(20)->get(['id', 'kind', 'body']),
            'failed' => (int) $mpo->mpo_failed,
            'orders' => $this->mpos->orderRecords($mpo),
        ]);
    }

    /** The medicines of one of their companies, to tick the ones they work with. */
    public function medicines(Request $request, Company $company): JsonResponse
    {
        if (! $request->user()->mpoCompanies()->where('companies.id', $company->id)->exists()) {
            throw new AuthorizationException;
        }

        $q = trim((string) $request->query('q', ''));

        return response()->json(['results' => Medicine::where('company_id', $company->id)->where('is_active', true)
            ->when($q !== '', fn ($w) => $w->where('brand_name', 'like', addcslashes($q, '%_\\').'%'))
            ->orderBy('brand_name')->orderBy('strength')->limit(60)->get(['id', 'brand_name', 'strength', 'form'])
            ->map(fn ($m) => ['id' => $m->id, 'name' => trim($m->brand_name.' '.$m->strength), 'form' => $m->form])]);
    }

    /** Add and remove chosen products. Editing later is allowed; the owner can also see and change the list. */
    public function choose(Request $request): RedirectResponse
    {
        $data = $request->validate(['add' => ['nullable', 'array'], 'add.*' => ['integer'], 'remove' => ['nullable', 'array'], 'remove.*' => ['integer']]);
        $mpo = $request->user();
        $current = $mpo->mpoProducts()->pluck('medicines.id')->all();
        $next = array_values(array_diff(array_unique([...$current, ...($data['add'] ?? [])]), $data['remove'] ?? []));

        $this->mpos->chooseProducts($mpo, $next);

        return back()->with('status', __('Saved.'));
    }

    public function submit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'medicine_id' => ['required', 'integer'], 'stock_status' => ['required', 'in:in_stock,pre_order'], 'arrive_days' => ['nullable', 'integer', 'in:2,3,4,5'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:10000000'], 'bonus_buy' => ['nullable', 'integer', 'min:1', 'max:100000'], 'bonus_free' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        try {
            $this->mpos->submit($request->user(), (int) $data['medicine_id'], $data);
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('Sent to us. It goes on the market after we look at it.'));
    }

    /** The MPO takes one of the anonymous bids. */
    public function accept(Request $request, MpoBid $bid): RedirectResponse
    {
        $listing = $bid->listing()->with('medicine', 'mpo')->firstOrFail();

        if ($listing->user_id !== $request->user()->id) {
            throw new AuthorizationException;
        }

        try {
            $this->bidding->accept($listing, $bid, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('Accepted. The order is made.'));
    }

    /** The MPO raises the percentage when a product is not selling. */
    public function raise(Request $request, MpoListing $listing): RedirectResponse
    {
        $data = $request->validate(['percent' => ['required', 'numeric', 'gt:0', 'max:90']]);

        try {
            $this->bidding->raise($listing->loadMissing('medicine'), (float) $data['percent'], $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('The percentage is raised.'));
    }

    public function withdraw(Request $request, MpoListing $listing): RedirectResponse
    {
        if ($listing->user_id !== $request->user()->id) {
            throw new AuthorizationException;
        }
        $this->mpos->withdraw($listing);

        return back()->with('status', __('Taken off.'));
    }
}
