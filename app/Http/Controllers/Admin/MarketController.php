<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\MarketCase;
use App\Models\MarketOrder;
use App\Models\MarketStatement;
use App\Models\Sector;
use App\Models\SellerPenalty;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Market\ListingQuestions;
use App\Services\Market\ListingReports;
use App\Services\Market\MarketCases;
use App\Services\Market\MarketOrders;
use App\Services\Market\Marketplace;
use App\Services\Market\Penalties;
use App\Services\Market\SellerReports;
use App\Services\Market\SellerTrust;
use App\Services\Market\Statements;
use App\Services\Media\ImageCompressor;
use App\Services\Stock\StockException;
use App\Support\Categories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** We read every listing and set our commission before it shows; we also open the sectors and say whose name is shown (spec ch 14, 15, 05). */
class MarketController extends Controller
{
    public function __construct(private Marketplace $market, private ActivityLogger $activity, private MarketOrders $orders, private Statements $statements, private MarketCases $cases, private SellerReports $reports, private SellerTrust $trust, private Penalties $penalties, private ListingReports $listingReports) {}

    public function index(): Response
    {
        $this->authorize('manage-customers');

        $row = fn (Listing $l) => [
            'id' => $l->id, 'title' => $l->title, 'description' => $l->description, 'sector' => $l->sector->name, 'unit' => $l->sector->unit,
            'seller' => $l->seller->business_name ?: $l->seller->name, 'seller_id' => $l->seller_id, 'show_name' => (bool) $l->seller->show_shop_name,
            'public_price' => $l->public_price !== null ? (float) $l->public_price : null, 'wholesale_price' => $l->wholesale_price !== null ? (float) $l->wholesale_price : null,
            'min_qty' => (float) $l->min_qty, 'delivery_charge' => (float) $l->delivery_charge, 'has_photo' => $l->photo_path !== null, 'flag' => $l->flag,
            'commission' => $l->commission_kind ? ['kind' => $l->commission_kind, 'value' => (float) $l->commission_value] : null,
            'request' => $l->commission_request, 'status' => $l->status,
        ];

        return Inertia::render('admin/market', [
            'queue' => $this->market->queue()->map(fn ($q) => $row($q['listing']) + ['waiting_days' => $q['waiting_days'], 'no_photo' => $q['no_photo']])->values(),
            'asking' => Listing::where('status', 'published')->whereNotNull('commission_request')->with(['sector', 'seller'])->latest('id')->get()->map($row),
            'published' => Listing::where('status', 'published')->with(['sector', 'seller'])->latest('approved_at')->limit(40)->get()->map($row),
            'sectors' => Sector::orderBy('sort')->orderBy('name')->get(),
            'categories' => Categories::all(),
        ]);
    }

    /** Who owes how much, and how many of each product sold (spec ch 14). Only complete sales. */
    public function commissions(Request $request): Response
    {
        $this->authorize('manage-customers');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : now()->format('Y-m');

        return Inertia::render('admin/market-commissions', [
            'month' => $month,
            'rows' => $this->orders->owed($month),
            'problems' => MarketOrder::where('status', 'not_received')->with('seller:id,name,business_name')->latest('not_received_at')->limit(50)->get()
                ->map(fn ($o) => ['id' => $o->id, 'number' => $o->number(), 'title' => $o->title, 'seller' => $o->seller->business_name ?: $o->seller->name, 'seller_id' => $o->seller_id, 'at' => $o->not_received_at->toIso8601String()]),
        ]);
    }

    /** The statements and what sellers answered. Paid and Problem wait for us. */
    public function statements(): Response
    {
        $this->authorize('manage-customers');
        $row = fn (MarketStatement $s) => [
            'id' => $s->id, 'month' => $s->month, 'seller' => $s->seller->business_name ?: $s->seller->name, 'seller_id' => $s->seller_id, 'orders' => $s->orders, 'sales' => (float) $s->sales,
            'commission' => (float) $s->commission, 'status' => $s->status, 'seller_note' => $s->seller_note, 'our_note' => $s->our_note, 'sent_at' => $s->sent_at->toIso8601String(),
            'stopped' => $s->seller->statementStopped(),
        ];

        return Inertia::render('admin/market-statements', [
            'waiting' => MarketStatement::whereIn('status', ['paid', 'problem'])->with('seller')->oldest('replied_at')->get()->map($row),
            'unanswered' => MarketStatement::whereIn('status', ['sent', 'suspended'])->with('seller')->oldest('sent_at')->get()->map($row),
            'done' => MarketStatement::where('status', 'verified')->with('seller')->latest('decided_at')->limit(30)->get()->map($row),
            'replyDays' => $this->statements->replyDays(),
        ]);
    }

    /** Complaints and returns: the serious ones (brought to us) first, and how many stand against each seller (spec ch 14). */
    public function cases(): Response
    {
        $this->authorize('manage-customers');
        $row = fn (MarketCase $c) => $this->cases->present($c) + ['number' => $c->order->number(), 'title' => $c->order->title, 'seller' => $c->seller->business_name ?: $c->seller->name, 'seller_id' => $c->seller_id];
        $load = fn (array $status) => MarketCase::whereIn('status', $status)->with(['order', 'seller'])->latest('id')->limit(60)->get()->map($row);

        return Inertia::render('admin/market-cases', [
            'serious' => $load(['escalated']), 'open' => $load(['open']), 'settled' => $load(['solved', 'returned', 'closed']),
            'sellers' => $this->withRank($this->cases->bySeller()), 'standing' => $this->standing(), 'steps' => SellerPenalty::STEPS, 'returnDays' => $this->cases->returnDays(),
        ]);
    }

    /** Every seller with a listing: how far they are trusted, where they rank, and what has been done about them. */
    /** Reports that a listing is a copy, with the listings that share its picture or its words beside each. */
    public function reports(): Response
    {
        $this->authorize('manage-customers');
        $row = fn (ListingReport $r) => [
            'id' => $r->id, 'reason' => $r->reason, 'status' => $r->status, 'our_note' => $r->our_note, 'at' => $r->created_at->toIso8601String(),
            'listing_id' => $r->listing_id, 'title' => $r->listing->title, 'seller' => $r->listing->seller->business_name ?: $r->listing->seller->name, 'listing_status' => $r->listing->status,
            'reporter' => $r->reporter?->business_name ?: $r->reporter?->name,
        ];

        return Inertia::render('admin/market-reports', [
            'open' => ListingReport::where('status', 'open')->with(['listing.seller', 'reporter'])->oldest()->get()->map(fn ($r) => $row($r) + ['alike' => $this->listingReports->lookAlikes($r->listing)]),
            'done' => ListingReport::whereIn('status', ['removed', 'dismissed'])->with(['listing.seller', 'reporter'])->latest('decided_at')->limit(30)->get()->map($row),
        ]);
    }

    public function resolveReport(Request $request, ListingReport $report): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['decision' => ['required', 'in:remove,dismiss'], 'note' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $this->listingReports->resolve($report, $request->user(), $data['decision'], $data['note']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['report' => __($e->getMessage())]);
        }
        $this->activity->record('market.report_resolved', null, ['report' => $report->id, 'decision' => $data['decision']], $request->user());

        return back()->with('status', __('Done.'));
    }

    private function standing()
    {
        $ranks = $this->reports->ranks();
        $limit = $this->penalties->missingLimit();

        return User::where('account_type', AccountType::Wholesaler)->whereHas('listings')->get()->map(function (User $s) use ($ranks, $limit) {
            $rank = $this->reports->rankOf($s, $ranks);
            $missing = $this->penalties->missing($s);

            return [
                'seller_id' => $s->id, 'seller' => $s->business_name ?: $s->name, 'rank' => $rank['score'], 'proven' => $rank['proven'], 'trust' => $this->trust->status($s),
                'missing' => $missing, 'slow' => $slow = app(ListingQuestions::class)->slow($s), 'watch' => $missing >= $limit || $slow >= app(ListingQuestions::class)->slowLimit(), 'next' => $this->penalties->next($s),
                'suspended' => $s->penalty_suspended_at !== null, 'withheld' => $s->payout_withheld_at !== null,
                'listings' => $s->listings()->where('status', 'published')->get(['id', 'title'])->map(fn ($l) => ['id' => $l->id, 'title' => $l->title])->values(),
                'history' => $this->penalties->history($s)->map(fn ($p) => ['step' => $p->step, 'reason' => $p->reason, 'at' => $p->created_at->toIso8601String(), 'lifted' => $p->lifted_at !== null])->values(),
            ];
        })->sortByDesc(fn ($r) => [$r['watch'], -$r['rank']])->values();
    }

    public function penalise(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($user->account_type === AccountType::Wholesaler, 404);
        $data = $request->validate(['step' => ['required', Rule::in(SellerPenalty::STEPS)], 'reason' => ['required', 'string', 'min:3', 'max:300'], 'listing_id' => ['nullable', 'integer']]);

        try {
            $this->penalties->issue($user, $request->user(), $data['step'], $data['reason'], ($data['listing_id'] ?? null) ? Listing::find($data['listing_id']) : null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['penalty' => __($e->getMessage())]);
        }
        $this->activity->record('market.penalty', $user, ['step' => $data['step']], $request->user());

        return back()->with('status', __('Done. The seller is told.'));
    }

    public function liftPenalty(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($user->account_type === AccountType::Wholesaler, 404);
        $this->penalties->lift($user);
        $this->activity->record('market.penalty_lifted', $user, [], $request->user());

        return back()->with('status', __('Lifted.'));
    }

    /** Trust a seller before the clean orders are there, or take that trust back. */
    public function trust(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($user->account_type === AccountType::Wholesaler, 404);
        $data = $request->validate(['trusted' => ['required', 'boolean']]);
        $user->forceFill(['seller_trusted_at' => $data['trusted'] ? now() : null])->save();
        $this->activity->record($data['trusted'] ? 'market.seller_trusted' : 'market.seller_trust_removed', $user, [], $request->user());

        return back()->with('status', __('Saved.'));
    }

    private function withRank($sellers)
    {
        $ranks = $this->reports->ranks();

        return $sellers->map(fn ($s) => $s + ['rank' => ($ranks[$s['seller_id']]['score'] ?? SellerReports::UNPROVEN_SCORE), 'proven' => (bool) ($ranks[$s['seller_id']]['proven'] ?? false)]);
    }

    public function decideCase(Request $request, MarketCase $case): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['decision' => ['required', 'in:return,close'], 'note' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $this->cases->decide($case->load(['order', 'seller']), $request->user(), $data['decision'], $data['note']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['case' => __($e->getMessage())]);
        }
        $this->activity->record('market.case_decided', null, ['case' => $case->id, 'decision' => $data['decision']], $request->user());

        return back()->with('status', __('Decided. Both sides are told.'));
    }

    public function generateStatements(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $sent = $this->statements->generate($data['month']);
        $this->activity->record('market.statements_sent', null, ['month' => $data['month'], 'sent' => $sent], $request->user());

        return back()->with('status', __(':n statements sent.', ['n' => $sent]));
    }

    public function verifyStatement(Request $request, MarketStatement $statement): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        try {
            $this->statements->verify($statement->load('seller'), $request->user(), $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['statement' => __($e->getMessage())]);
        }
        $this->activity->record('market.statement_verified', null, ['statement' => $statement->id], $request->user());

        return back()->with('status', __('Checked.'));
    }

    public function reopenStatement(Request $request, MarketStatement $statement): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $this->statements->reopen($statement->load('seller'), $request->user(), $data['note']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['statement' => __($e->getMessage())]);
        }
        $this->activity->record('market.statement_reopened', null, ['statement' => $statement->id], $request->user());

        return back()->with('status', __('Sent back to the seller.'));
    }

    public function liftStop(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($user->account_type === AccountType::Wholesaler, 404);
        $this->statements->lift($user);
        $this->activity->record('market.selling_restored', $user, [], $request->user());

        return back()->with('status', __('Selling is back on.'));
    }

    public function approve(Request $request, Listing $listing): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['kind' => ['required', Rule::in(['percent', 'taka'])], 'value' => ['required', 'numeric', 'min:0', 'max:1000000'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(fn () => $this->market->approve($listing->load('seller'), $request->user(), $data['kind'], (float) $data['value'], $data['note'] ?? null), 'listing.approved', $listing, $request, 'Published.');
    }

    public function reject(Request $request, Listing $listing): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->attempt(fn () => $this->market->reject($listing->load('seller'), $request->user(), $data['note']), 'listing.rejected', $listing, $request, 'Turned down.');
    }

    public function commission(Request $request, Listing $listing): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['kind' => ['required', Rule::in(['percent', 'taka'])], 'value' => ['required', 'numeric', 'min:0', 'max:1000000']]);

        return $this->attempt(fn () => $this->market->setCommission($listing, $data['kind'], (float) $data['value']), 'listing.commission', $listing, $request, 'Saved.');
    }

    public function storeSector(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $this->sectorData($request);
        $sector = Sector::create($data);
        $this->activity->record('sector.created', null, ['sector' => $sector->slug], $request->user());

        return back()->with('status', __('Saved.'));
    }

    public function updateSector(Request $request, Sector $sector): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $sector->update($this->sectorData($request, $sector));
        $this->activity->record('sector.updated', null, ['sector' => $sector->slug], $request->user());

        return back()->with('status', __('Saved.'));
    }

    /** The banner at the top of one kind of business: a wide picture, replaced at once and the old one deleted. */
    public function sectorHero(Request $request, Sector $sector): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:6144']]);

        $old = $sector->hero_path;
        $sector->update(['hero_path' => app(ImageCompressor::class)->store($request->file('image'), 'sectors', 2400)]);

        if ($old && Storage::exists($old)) {
            Storage::delete($old);
        }

        $this->activity->record('sector.hero', null, ['sector' => $sector->slug], $request->user());

        return back()->with('status', __('Saved.'));
    }

    /** Whether a seller's own name is shown instead of ours (spec ch 05): a deal we make with them. */
    public function shopName(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($user->account_type === AccountType::Wholesaler, 404);
        $data = $request->validate(['show' => ['required', 'boolean']]);

        $user->forceFill(['show_shop_name' => (bool) $data['show']])->save();
        $this->activity->record($data['show'] ? 'seller.name_on' : 'seller.name_off', $user, [], $request->user());

        return back()->with('status', $data['show'] ? __('Their name is shown.') : __('Our name is shown.'));
    }

    private function sectorData(Request $request, ?Sector $sector = null): array
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('sectors', 'slug')->ignore($sector?->id), Rule::notIn(['medicine'])],
            'name' => ['required', 'string', 'max:80'],
            'name_bn' => ['nullable', 'string', 'max:80'],
            'unit' => ['required', 'string', 'max:20'],
            'allow_fraction' => ['required', 'boolean'],
            'premium_over' => ['nullable', 'numeric', 'min:1', 'max:1000000000'],
            'preorder' => ['required', 'boolean'],
            'chat' => ['required', 'boolean'],
            'return_rule' => ['nullable', 'string', 'max:300'],
            'fields' => ['nullable', 'array'], 'fields.*' => [Rule::in(Sector::FIELDS)],
            'custom_orders' => ['sometimes', 'boolean'], 'catalog_required' => ['sometimes', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'tagline' => ['nullable', 'string', 'max:160'], 'tagline_bn' => ['nullable', 'string', 'max:160'],
        ]);

        // A field left empty means "none": the premium amount, the Bangla name, the return rule.
        $data['fields'] = array_values(array_unique((array) ($data['fields'] ?? [])));
        $data['custom_orders'] = (bool) ($data['custom_orders'] ?? false);
        $data['catalog_required'] = (bool) ($data['catalog_required'] ?? false);

        return $data + ['premium_over' => null, 'name_bn' => null, 'return_rule' => null, 'tagline' => null, 'tagline_bn' => null] + ['sort' => (int) $request->input('sort', 0)];
    }

    private function attempt(callable $do, string $action, Listing $listing, Request $request, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['listing' => __($e->getMessage())]);
        }

        $this->activity->record($action, null, ['listing' => $listing->id], $request->user());

        return back()->with('status', __($done));
    }
}
