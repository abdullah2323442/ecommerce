<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CustomerRate;
use App\Models\LoginEvent;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Tier;
use App\Models\TierChange;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\Bin;
use App\Services\Ledger\Receivables;
use App\Services\Messages\Messenger;
use App\Services\Pricing\TierAssigner;
use App\Services\Sales\CustomerFlags;
use App\Services\Sales\CustomerTransactions;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\TierUpgrade;
use App\Services\Stock\StockService;
use App\Support\Categories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Everything about one customer on one page (spec ch 25: no jumping between pages). Admin only. */
class CustomerController extends Controller
{
    private const CUSTOMER_TYPES = [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler];

    public function __construct(
        private ActivityLogger $activity,
        private TierAssigner $tiers,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-customers');

        $search = trim((string) $request->query('q', ''));
        $type = $request->query('type');
        $status = in_array($request->query('status'), ['active', 'suspended'], true) ? $request->query('status') : null;
        $sort = $request->query('sort') === 'newest' ? 'newest' : 'name';

        $customers = User::query()
            ->with('tier:id,name')
            ->whereIn('account_type', array_map(fn (AccountType $t) => $t->value, self::CUSTOMER_TYPES))
            ->when($type && AccountType::tryFrom($type), fn ($q) => $q->where('account_type', $type))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like);
            }))
            ->when($status === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->when($status === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when($sort === 'newest', fn ($q) => $q->latest(), fn ($q) => $q->orderBy('name'))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'email' => $u->email,
                'account_type' => $u->account_type->value,
                'tier' => $u->tier?->name,
                'suspended' => $u->isSuspended(),
                'joined' => $u->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/customers/index', [
            'customers' => $customers,
            'filters' => ['q' => $search, 'type' => $type, 'status' => $status, 'sort' => $sort],
            'types' => array_map(fn (AccountType $t) => $t->value, self::CUSTOMER_TYPES),
            'upgrades' => $request->user()->can('manage-customers') ? app(TierUpgrade::class)->candidates() : [],
        ]);
    }

    public function show(User $user): Response
    {
        $this->authorize('manage-customers');

        // A log of who opened whose data and when (spec ch 26). The owner's own looks are not noise.
        if (! request()->user()->isSuperAdmin()) {
            $this->activity->record('customer.viewed', $user, [], request()->user());
        }

        return Inertia::render('admin/customers/show', [
            'customer' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'account_type' => $user->account_type->value,
                'tier_id' => $user->tier_id,
                'suspended_at' => $user->suspended_at?->toIso8601String(),
                'suspended_until' => $user->suspended_until?->toIso8601String(),
                'suspend_reason' => $user->suspend_reason,
                'area' => $user->area,
                'business_name' => $user->business_name,
                'categories' => (array) $user->categories,
                'moq' => $user->moq === null ? null : (float) $user->moq,
                'pay_cod' => (bool) $user->pay_cod,
                'pay_advance' => (bool) $user->pay_advance,
                'show_monthly_total' => (bool) $user->show_monthly_total,
                'free_delivery_over' => $user->free_delivery_over !== null ? (float) $user->free_delivery_over : null,
                'late_cancellations' => app(CustomerFlags::class)->lateCancellations($user),
                'flagged' => app(CustomerFlags::class)->isFlagged($user),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'offersTaken' => DB::table('invoice_lines')->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')->join('medicines', 'medicines.id', '=', 'invoice_lines.medicine_id')
                ->where('invoices.customer_id', $user->id)->whereNotNull('invoice_lines.offer_product_id')->whereNull('invoice_lines.removed_at')->whereIn('invoices.status', ['final', 'verified', 'placed', 'draft'])
                ->latest('invoice_lines.id')->limit(15)->get(['medicines.brand_name as name', 'invoice_lines.quantity', 'invoice_lines.unit_price', 'invoices.number', 'invoices.status'])
                ->map(fn ($r) => ['name' => $r->name, 'boxes' => (float) $r->quantity, 'price' => (float) $r->unit_price, 'memo' => $r->number, 'status' => $r->status]),
            'transactions' => app(CustomerTransactions::class)->for($user),
            // What this customer owes us on memos left on credit; only those who may see the reports see money (the credit ledger, spec ch 28).
            'owed' => request()->user()->can('view-reports') ? app(Receivables::class)->owedBy($user) : null,
            'tiers' => Tier::orderBy('name')->get(['id', 'name', 'discount_percent']),
            'categoryOptions' => collect(Categories::all())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'manualRates' => CustomerRate::query()
                ->where('user_id', $user->id)
                ->join('medicines', 'medicines.id', '=', 'customer_rates.medicine_id')
                ->orderBy('medicines.brand_name')
                ->get(['customer_rates.id', 'customer_rates.medicine_id', 'customer_rates.discount_percent', 'customer_rates.fixed_price', 'medicines.brand_name', 'medicines.strength']),
            'tierHistory' => TierChange::query()->where('user_id', $user->id)->latest('created_at')->limit(10)->get(),
            'activity' => ActivityLog::query()
                ->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)
                ->latest('created_at')->limit(15)->get(['id', 'action', 'changes', 'actor_id', 'created_at']),
            'logins' => LoginEvent::query()->where('user_id', $user->id)->latest('created_at')->limit(10)
                ->get(['id', 'outcome', 'ip', 'is_suspicious', 'reason', 'created_at']),
        ]);
    }

    public function updateTier(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $this->ensureCustomer($user);

        $data = $request->validate(['tier_id' => ['nullable', 'integer', Rule::exists('tiers', 'id')]]);
        $to = isset($data['tier_id']) ? Tier::find($data['tier_id']) : null;

        if (($user->tier_id ?? null) !== ($to?->id)) {
            $wasPercent = (float) ($user->tier?->discount_percent ?? -1);
            $this->tiers->assign($user, $to, $request->user());

            // Moving a shop up sends a congratulations notice (spec 4.4.10).
            if ($to && (float) $to->discount_percent > $wasPercent && $user->account_type === AccountType::Paikari) {
                app(Messenger::class)->send($user, 'tier_up', ['name' => $to->name]);
            }
        }

        return back()->with('status', __('Tier updated.'));
    }

    /**
     * The account type and, for a bulk buyer, the categories they may see, their minimum order quantity and how they
     * pay (spec ch 14, 25: "upgrade to wholesaler"). Each customer has their own terms.
     */
    public function trade(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $this->ensureCustomer($user);

        $data = $request->validate([
            'account_type' => ['required', Rule::in(array_map(fn (AccountType $t) => $t->value, self::CUSTOMER_TYPES))],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['in:'.implode(',', array_keys(Categories::all()))],
            'moq' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'pay_cod' => ['required', 'boolean'],
            'pay_advance' => ['required', 'boolean'],
            'business_name' => ['nullable', 'string', 'max:150'],
        ]);

        $bulk = $data['account_type'] === AccountType::Wholesaler->value;
        if ($bulk && ! $data['pay_cod'] && ! $data['pay_advance']) {
            throw ValidationException::withMessages(['pay_cod' => __('Allow at least one way to pay.')]);
        }

        $before = $user->account_type->value;
        $user->forceFill([
            'account_type' => $data['account_type'],
            'business_name' => $data['business_name'] ?? $user->business_name,
            'categories' => $bulk ? array_values($data['categories'] ?? ['medicine']) : null,
            'moq' => $bulk ? ($data['moq'] ?? null) : null,
            'pay_cod' => (bool) $data['pay_cod'],
            'pay_advance' => (bool) $data['pay_advance'],
        ])->save();
        $this->activity->record('customer.trade_terms', $user, ['from' => $before, 'to' => $data['account_type'], 'categories' => $user->categories, 'moq' => $user->moq, 'cod' => (bool) $data['pay_cod'], 'advance' => (bool) $data['pay_advance']], $request->user());

        return back()->with('status', __('Saved.'));
    }

    /** Whether this shop sees its own monthly business total (spec 4.4.10). */
    public function monthlyTotal(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $this->ensureCustomer($user);
        $data = $request->validate(['show' => ['required', 'boolean']]);

        $user->forceFill(['show_monthly_total' => (bool) $data['show']])->save();
        $this->activity->record('customer.monthly_total', $user, ['show' => (bool) $data['show']], $request->user());

        return back()->with('status', __('Saved.'));
    }

    /** The amount past which this shop's delivery is free (spec ch 07 step 9-B). Empty goes back to the site-wide amount. */
    public function freeDelivery(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $this->ensureCustomer($user);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0', 'max:100000000']]);

        $amount = ($data['amount'] ?? '') === '' || ($data['amount'] ?? null) === null ? null : (float) $data['amount'];
        $user->forceFill(['free_delivery_over' => $amount])->save();
        $this->activity->record('customer.free_delivery', $user, ['amount' => $amount], $request->user());

        return back()->with('status', __('Saved.'));
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $this->ensureCustomer($user);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        // A set number of days lifts itself when the time is up; no number means until someone reopens it (spec ch 26).
        $user->forceFill([
            'suspended_at' => now(), 'suspend_reason' => $data['reason'],
            'suspended_until' => isset($data['days']) ? now()->addDays((int) $data['days']) : null,
        ])->save();
        $this->activity->record('customer.suspended', $user, ['reason' => $data['reason'], 'days' => $data['days'] ?? null], $request->user());

        return back()->with('status', __('Account suspended.'));
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $this->ensureCustomer($user);

        $user->forceFill(['suspended_at' => null, 'suspended_until' => null, 'suspend_reason' => null])->save();
        $this->activity->record('customer.reactivated', $user, [], $request->user());

        return back()->with('status', __('Account reactivated.'));
    }

    /** Create or update the manual rate for one medicine. Never touched by tier changes (PRC-2). */
    public function saveRate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-customers');
        $this->ensureCustomer($user);

        $data = $request->validate([
            'medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_without:fixed_price'],
            'fixed_price' => ['nullable', 'numeric', 'gt:0', 'required_without:discount_percent'],
            'price_unit' => ['nullable', 'in:piece,strip,box'],
            'force_loss' => ['sometimes', 'boolean'],
        ]);

        // Exactly one of percent or taka is stored; the other is derived when read.
        $percent = $data['discount_percent'] ?? null;
        $fixed = $percent === null ? ($data['fixed_price'] ?? null) : null;

        // The owner may type the taka price for a box, a strip or a piece (spec ch 04, 28); it is kept per piece, and the percent it works out to is shown after saving.
        $medicine = Medicine::findOrFail($data['medicine_id']);
        $unit = $data['price_unit'] ?? 'piece';
        if ($fixed !== null && $unit !== 'piece') {
            $fixed = round((float) $fixed * InvoiceService::fraction($medicine, 'piece') / InvoiceService::fraction($medicine, $unit), 2);
        }

        // A price at or above the printed piece price gives no discount at all, and is almost always a price typed for the wrong unit.
        if ($fixed !== null && (float) $medicine->mrp_piece > 0 && (float) $fixed >= (float) $medicine->mrp_piece) {
            throw ValidationException::withMessages(['fixed_price' => __('This works out to :given a piece, and this medicine is printed at :price a piece. Type a price below that, check you chose the right unit (box, strip or piece), or use a percent.', ['price' => number_format((float) $medicine->mrp_piece, 2), 'given' => number_format((float) $fixed, 2)])]);
        }

        // LOS-1: a rate that loses money on every lot in stock is blocked when it is set. LOS-5: the admin may force it.
        $loses = app(StockService::class)->rateWouldLose($medicine, $percent === null ? null : (float) $percent, $fixed === null ? null : (float) $fixed);
        if ($loses && ! ($data['force_loss'] ?? false)) {
            throw ValidationException::withMessages(['loss' => __('This rate is below what this medicine cost. Raise it, or confirm to sell at a loss.')]);
        }

        $rate = CustomerRate::updateOrCreate(
            ['user_id' => $user->id, 'medicine_id' => $data['medicine_id']],
            ['discount_percent' => $percent, 'fixed_price' => $fixed],
        );

        $this->activity->record('customer.manual_rate_set', $user, [
            'medicine_id' => $data['medicine_id'],
            'discount_percent' => $percent,
            'fixed_price' => $fixed,
            'brand' => Medicine::whereKey($data['medicine_id'])->value('brand_name'),
            'loss_forced' => $loses,
        ], $request->user());

        $saved = $rate->wasRecentlyCreated ? __('Manual rate added.') : __('Manual rate updated.');
        if ($fixed !== null && (float) $medicine->mrp_piece > 0) {
            $saved .= ' '.__('That is :n% off the printed price.', ['n' => rtrim(rtrim(number_format((1 - (float) $fixed / (float) $medicine->mrp_piece) * 100, 2), '0'), '.')]);
        }

        return back()->with('status', $saved);
    }

    public function deleteRate(Request $request, User $user, CustomerRate $rate): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($rate->user_id === $user->id, 404);

        $this->activity->record('customer.manual_rate_removed', $user, ['medicine_id' => $rate->medicine_id], $request->user());
        app(Bin::class)->keep($rate, $request->user());
        $rate->delete();

        return back()->with('status', __('Manual rate removed.'));
    }

    /** Only customer accounts have tiers, rates and suspension here; staff and admin are managed elsewhere. */
    private function ensureCustomer(User $user): void
    {
        abort_unless(in_array($user->account_type, self::CUSTOMER_TYPES, true), 404);
    }
}
