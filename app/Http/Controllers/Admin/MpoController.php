<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MpoBid;
use App\Models\MpoListing;
use App\Models\User;
use App\Services\Mpo\MpoService;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The owner's side of the MPO sector (spec ch 10): open accounts (only the owner can), set the territory and companies, read the
 * profile, review what is submitted, and set the markets and the price in each. Only the owner sees who an MPO is.
 */
class MpoController extends Controller
{
    public function __construct(private MpoService $mpos) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');

        $mpos = User::where('account_type', AccountType::Mpo->value)->withCount(['mpoProducts as products_count'])->orderBy('area')->orderBy('mpo_number')->get();
        $counts = MpoListing::selectRaw('user_id, count(*) as submitted, sum(case when status = ? then 1 else 0 end) as live', ['approved'])->groupBy('user_id')->get()->keyBy('user_id');

        return Inertia::render('admin/mpo/index', [
            // Grouped by territory automatically: changing a territory changes the grouping (spec ch 10).
            'groups' => $mpos->groupBy(fn (User $u) => $u->area ?: '')->map(fn ($rows, $area) => [
                'area' => $area, 'count' => $rows->count(),
                'mpos' => $rows->map(fn (User $u) => [
                    'id' => $u->id, 'number' => $u->mpo_number, 'name' => $u->name, 'phone' => $u->phone, 'closed' => $u->isSuspended(), 'products' => (int) $u->products_count,
                    'submitted' => (int) ($counts[$u->id]->submitted ?? 0), 'live' => (int) ($counts[$u->id]->live ?? 0),
                ])->values(),
            ])->values(),
            'companies' => Company::where('is_active', true)->orderBy('name')->limit(300)->get(['id', 'name']),
            'waiting' => MpoListing::where('status', 'submitted')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'], 'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'max:100'], 'area' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:500'],
            'company_ids' => ['required', 'array', 'min:1'], 'company_ids.*' => ['integer', 'exists:companies,id'],
        ]);

        $mpo = $this->mpos->open($data, $request->user());

        return redirect()->route('admin.mpo.show', $mpo)->with('status', __('MPO account opened.'));
    }

    public function show(User $user): Response
    {
        $this->authorize('manage-accounts');
        $this->mpo($user);

        return Inertia::render('admin/mpo/show', [
            'mpo' => [
                'id' => $user->id, 'number' => $user->mpo_number, 'name' => $user->name, 'phone' => $user->phone, 'email' => $user->email, 'area' => $user->area, 'note' => $user->mpo_note,
                'closed' => $user->isSuspended(), 'failed' => (int) $user->mpo_failed, 'submitted' => MpoListing::where('user_id', $user->id)->count(), 'approved' => MpoListing::where('user_id', $user->id)->where('status', 'approved')->count(),
                'sold' => \App\Models\Preorder::whereIn('mpo_listing_id', MpoListing::where('user_id', $user->id)->select('id'))->where('status', 'fulfilled')->count(), 'since' => $user->created_at?->toIso8601String(), 'company_ids' => $user->mpoCompanies()->pluck('companies.id'),
                'products' => $user->mpoProducts()->with('company:id,name')->orderBy('brand_name')->get()->map(fn ($m) => ['id' => $m->id, 'name' => trim($m->brand_name.' '.$m->strength), 'company' => $m->company?->name]),
            ],
            'companies' => Company::where('is_active', true)->orderBy('name')->limit(300)->get(['id', 'name']),
            'listings' => MpoListing::where('user_id', $user->id)->with('medicine:id,brand_name,strength')->latest('id')->limit(50)->get()
                ->map(fn (MpoListing $l) => ['id' => $l->id, 'name' => trim($l->medicine->brand_name.' '.$l->medicine->strength), 'status' => $l->status, 'stock_status' => $l->stock_status]),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $this->mpo($user);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'area' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:500'], 'phone' => ['nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)]]);

        $user->forceFill(['name' => $data['name'], 'area' => $data['area'] ?? null, 'mpo_note' => $data['note'] ?? null, 'phone' => $data['phone'] ?? null])->save();

        return back()->with('status', __('Saved.'));
    }

    /** Add or remove a company at any time; a new one brings its medicine list to the MPO. */
    public function companies(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $this->mpo($user);
        $data = $request->validate(['company_ids' => ['required', 'array', 'min:1'], 'company_ids.*' => ['integer', 'exists:companies,id']]);

        $this->mpos->setCompanies($user, $data['company_ids'], $request->user());

        return back()->with('status', __('Saved.'));
    }

    public function close(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $this->mpo($user);
        $this->mpos->close($user, $request->user());

        return back()->with('status', __('Account closed. Its products are off the market. Orders already made must still be completed.'));
    }

    // ------------------------------------------------------------------ review

    /** What is waiting for a decision, with the same medicine from several MPOs side by side to compare. */
    public function listings(): Response
    {
        $this->authorize('manage-accounts');

        $rows = MpoListing::whereIn('status', ['submitted', 'approved'])->with('mpo:id,name,area,mpo_number', 'medicine:id,brand_name,strength,mrp_box,mrp_piece,packaging_type,company_id', 'medicine.company:id,name')
            ->orderByRaw("case status when 'submitted' then 0 else 1 end")->orderBy('medicine_id')->get();

        return Inertia::render('admin/mpo/listings', [
            'groups' => $rows->groupBy('medicine_id')->map(fn ($g) => [
                'medicine' => trim($g->first()->medicine->brand_name.' '.$g->first()->medicine->strength), 'company' => $g->first()->medicine->company?->name,
                'mrp' => (float) ($g->first()->medicine->packaging_type === 'box' ? $g->first()->medicine->mrp_box : $g->first()->medicine->mrp_piece),
                'listings' => $g->map(fn (MpoListing $l) => [
                    // The owner sees the real person behind each listing; no one else does.
                    'id' => $l->id, 'status' => $l->status, 'mpo' => $l->mpo->name, 'area' => $l->mpo->area, 'alias' => $l->alias(),
                    'stock_status' => $l->stock_status, 'arrive_days' => $l->arrive_days, 'quantity' => $l->quantity, 'bonus_buy' => $l->bonus_buy, 'bonus_free' => $l->bonus_free,
                    'markets' => (array) $l->markets, 'price_wholesale' => $l->price_wholesale === null ? null : (float) $l->price_wholesale,
                    'price_paikari' => $l->price_paikari === null ? null : (float) $l->price_paikari, 'price_public' => $l->price_public === null ? null : (float) $l->price_public,
                    'show_bonus' => $l->show_bonus, 'show_quantity' => $l->show_quantity,
                    // Only the owner sees which buyer offered how much.
                    'bids' => MpoBid::where('mpo_listing_id', $l->id)->where('status', 'open')->with('bidder:id,name,phone')->orderBy('id')->get()
                        ->map(fn ($b) => ['id' => $b->id, 'who' => $b->bidder?->name, 'phone' => $b->bidder?->phone, 'percent' => (float) $b->percent, 'boxes' => (int) $b->boxes])->values(),
                ])->values(),
            ])->values(),
        ]);
    }

    public function approve(Request $request, MpoListing $listing): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'markets' => ['required', 'array', 'min:1'], 'markets.*' => ['in:'.implode(',', MpoListing::MARKETS)],
            'price_wholesale' => ['nullable', 'numeric', 'gt:0', 'max:10000000'], 'price_paikari' => ['nullable', 'numeric', 'gt:0', 'max:10000000'], 'price_public' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
            'show_bonus' => ['sometimes', 'boolean'], 'show_quantity' => ['sometimes', 'boolean'],
            'stock_status' => ['required', 'in:in_stock,pre_order'], 'arrive_days' => ['nullable', 'integer', 'in:2,3,4,5'],
        ]);

        try {
            $this->mpos->approve($listing, $data, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('Approved. It is on the market now.'));
    }

    public function reject(Request $request, MpoListing $listing): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        try {
            $this->mpos->reject($listing, $data['note'], $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['listing' => __($e->getMessage())]);
        }

        return back()->with('status', __('Declined.'));
    }

    private function mpo(User $user): void
    {
        abort_unless($user->account_type === AccountType::Mpo, 404);
    }
}
