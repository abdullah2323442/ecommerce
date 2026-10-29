<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockEntryDraft;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockSource;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\LotCostCorrection;
use App\Services\Stock\LotWarnings;
use App\Services\Stock\MismatchService;
use App\Services\Stock\PurchasePricing;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use App\Support\MedicinePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock screens for Siam's pharmacy (spec ch 06). Admin only. Nothing here is ever shown to a visitor,
 * a paikari buyer or any public page: stock and purchase prices stay behind this one gate.
 */
class StockController extends Controller
{
    public function __construct(private StockService $stock, private PurchasePricing $pricing, private ActivityLogger $activity, private LotWarnings $warnings, private MismatchService $mismatches, private LotCostCorrection $costs) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-stock');

        $q = trim((string) $request->query('q', ''));

        $rows = StockLot::query()
            ->join('medicines', 'medicines.id', '=', 'stock_lots.medicine_id')
            ->leftJoin('companies', 'companies.id', '=', 'medicines.company_id')
            ->where('stock_lots.units_remaining', '>', 0)
            ->when($q !== '', fn ($query) => $query->where('medicines.brand_name', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->groupBy('medicines.id', 'medicines.brand_name', 'medicines.strength', 'medicines.form', 'medicines.packaging_type', 'companies.name')
            ->orderBy('medicines.brand_name')
            ->select([
                'medicines.id', 'medicines.brand_name', 'medicines.strength', 'medicines.form', 'medicines.packaging_type',
                'companies.name as company',
                DB::raw('sum(stock_lots.units_remaining) as units'),
                DB::raw('sum(stock_lots.units_remaining * stock_lots.unit_cost) as value'),
                DB::raw('min(stock_lots.unit_cost) as min_cost'),
                DB::raw('max(stock_lots.unit_cost) as max_cost'),
            ])
            ->paginate(30)
            ->withQueryString();

        $lots = $this->warnings->summary($rows->pluck('id')->all());
        $rows->through(fn ($row) => $row->setAttribute('lots', $lots[$row->id]['lots'] ?? 0)->setAttribute('small_old', $lots[$row->id]['small_old'] ?? 0)->setAttribute('fragmented', $lots[$row->id]['warn'] ?? false));

        return Inertia::render('admin/stock/index', [
            'openMismatches' => $this->mismatches->openCount(),
            'rows' => $rows,
            'query' => $q,
            'stockValue' => $this->stock->stockValue(),
            'masterSync' => $this->stock->masterSyncEnabled(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/stock/receive', [
            'sources' => StockSource::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'default_percent']),
            'today' => now()->toDateString(),
            'draft' => StockEntryDraft::where('user_id', auth()->id())->first(['payload', 'updated_at']),
        ]);
    }

    /** A purchase entry stopped halfway is kept, one per person. It adds nothing to stock. */
    public function saveDraft(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');
        $request->validate(['payload' => ['required', 'array'], 'payload.lines' => ['nullable', 'array', 'max:200']]);
        $payload = $request->input('payload');

        abort_if(strlen(json_encode($payload)) > 400000, 422);
        StockEntryDraft::updateOrCreate(['user_id' => $request->user()->id], ['payload' => $payload]);

        return back()->with('status', __('Draft saved. It has not changed any stock.'));
    }

    public function clearDraft(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');
        StockEntryDraft::where('user_id', $request->user()->id)->delete();

        return back()->with('status', __('Draft removed.'));
    }

    /** What one line would cost, for the live preview on the entry screen. The maths is the same one used to save. */
    public function quote(Request $request): JsonResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'source_id' => ['required', 'integer', Rule::exists('stock_sources', 'id')],
            'medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
        ]);

        $medicine = Medicine::findOrFail($data['medicine_id']);

        return response()->json([
            'percent' => $this->pricing->resolvePercent(StockSource::findOrFail($data['source_id']), $medicine),
            'mrp_unit' => StockService::unitMrp($medicine),
            'unit' => $medicine->packaging_type === 'box' ? 'box' : 'piece',
        ]);
    }

    /** Companies by the start of their name, for "add many from one company" on the entry screen. */
    public function companies(Request $request): JsonResponse
    {
        $this->authorize('manage-stock');
        $q = trim((string) $request->query('q', ''));

        return response()->json(['results' => mb_strlen($q) < 2 ? [] : Company::where('name', 'like', addcslashes($q, '%_\\').'%')->orderBy('name')->limit(8)->get(['id', 'name'])]);
    }

    /** One company's medicines (a long list is cut to the first 80, narrowed by what is typed), so several can be added to the purchase at once. */
    public function companyMedicines(Request $request, Company $company): JsonResponse
    {
        $this->authorize('manage-stock');
        $q = trim((string) $request->query('q', ''));

        $rows = Medicine::where('company_id', $company->id)->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where('brand_name', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->with('company:id,name')->orderBy('brand_name')->orderBy('strength')->limit(80)->get(['id', 'company_id', 'brand_name', 'strength', 'form']);

        return response()->json(['results' => $rows->map(fn (Medicine $m) => ['id' => $m->id, 'brand_name' => $m->brand_name, 'strength' => $m->strength, 'form' => $m->form, 'company' => ['name' => $m->company?->name]])->values()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'source_id' => ['required', 'integer', Rule::exists('stock_sources', 'id')->where('is_active', true)],
            'invoice_number' => ['nullable', 'string', 'max:60'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            // Paid in full unless a smaller amount is typed; the rest is owed to the company by the due date (the ledger, spec ch 28).
            'paid_now' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'lines.*.units' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.bonus' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.company_percent' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
            'lines.*.mpo_percent' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
            'lines.*.shelf' => ['nullable', 'string', 'max:40'],
            'lines.*.expiry_date' => ['nullable', 'date', 'after:today'],
        ]);

        foreach ($data['lines'] as $i => $line) {
            if (StockService::unitMrp(Medicine::findOrFail($line['medicine_id'])) <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.medicine_id" => __('This medicine has no MRP, so its purchase price cannot be worked out.')]);
            }
        }

        try {
            $invoice = $this->stock->receive(StockSource::findOrFail($data['source_id']), $data, $request->user(), (bool) ($data['confirm_duplicate'] ?? false));
        } catch (StockException $e) {
            $field = match (true) {
                $e->reason === 'duplicate_invoice' => 'invoice_number',
                str_contains($e->getMessage(), 'day the rest is due') => 'due_date',
                str_contains($e->getMessage(), 'amount paid') => 'paid_now',
                default => 'lines',
            };

            throw ValidationException::withMessages([$field => __($e->getMessage())]);
        }

        StockEntryDraft::where('user_id', $request->user()->id)->delete();
        $this->activity->record('stock.received', $invoice, ['lines' => count($data['lines']), 'total_cost' => (string) $invoice->total_cost], $request->user());

        return to_route('admin.stock.index')->with('status', __('Stock saved: :n lines, :total taka.', ['n' => count($data['lines']), 'total' => number_format((float) $invoice->total_cost, 2)]));
    }

    public function show(Medicine $medicine): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/stock/show', [
            'medicine' => MedicinePresenter::one($medicine->load('company:id,name,logo_url,logo_path')),
            'unitMrp' => StockService::unitMrp($medicine),
            'onHand' => $this->stock->onHand($medicine),
            'lots' => StockLot::where('medicine_id', $medicine->id)->with('invoice.source:id,name')->orderByDesc('id')->get()->map(fn (StockLot $l) => [
                'id' => $l->id,
                'small_old' => $this->warnings->isSmallAndOld($l),
                'mrp_unit' => (float) $l->mrp_unit_at_purchase,
                'source' => $l->invoice?->source?->name,
                'invoice_number' => $l->invoice?->invoice_number,
                'date' => $l->invoice?->invoice_date?->toDateString(),
                'units_received' => (float) $l->units_received,
                'units_bonus' => (float) $l->units_bonus,
                'units_remaining' => (float) $l->units_remaining,
                'unit_cost' => (float) $l->unit_cost,
                'purchase_percent' => (float) $l->purchase_percent,
                'shelf' => $l->shelf,
                'expiry_date' => $l->expiry_date?->toDateString(),
            ]),
            'movements' => StockMovement::where('stock_movements.medicine_id', $medicine->id)
                ->leftJoin('users', 'users.id', '=', 'stock_movements.user_id')
                ->orderByDesc('stock_movements.id')->limit(30)
                ->get(['stock_movements.type', 'stock_movements.units', 'stock_movements.unit_cost', 'stock_movements.unit_price', 'stock_movements.is_loss', 'stock_movements.reason', 'stock_movements.created_at', 'users.name as by']),
            'reasons' => StockService::REASONS,
            'lotsLeft' => StockLot::where('medicine_id', $medicine->id)->where('units_remaining', '>', 0)->count(),
            'manyLots' => $this->warnings->manyLots(),
            'mrpMark' => $medicine->mrp_changed_at && $medicine->mrp_changed_at->gt(now()->subDays(max(1, (int) Setting::read('stock.mrp_mark_days', 30))))
                ? ['at' => $medicine->mrp_changed_at->toDateString(), 'was' => (float) $medicine->mrp_before] : null,
            'reorderLevel' => $medicine->reorder_level === null ? null : (string) $medicine->reorder_level,
        ]);
    }

    /** COR-1: the admin fixes a stock number, with a reason. Not a sale, not counted as a loss. */
    public function correct(Request $request, Medicine $medicine): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'on_hand' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'reason' => ['required', Rule::in(StockService::REASONS)],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $delta = $this->stock->correct($medicine, (float) $data['on_hand'], $data['reason'], $request->user(), $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['on_hand' => $e->getMessage()]);
        }

        $this->activity->record('stock.corrected', $medicine, ['delta' => $delta, 'reason' => $data['reason']], $request->user());

        return back()->with('status', __('Stock corrected.'));
    }

    /** Correct the purchase price of one lot (admin only). Tells what would be at a loss first and wants a yes. */
    public function lotCost(Request $request, StockLot $lot): RedirectResponse
    {
        $this->authorize('manage-stock');
        $data = $request->validate(['percent' => ['required', 'numeric', 'min:0', 'max:99.9'], 'confirm_loss' => ['sometimes', 'boolean']]);

        try {
            $result = $this->costs->apply($lot->load('medicine'), (float) $data['percent'], $request->user(), (bool) ($data['confirm_loss'] ?? false));
        } catch (StockException $e) {
            throw ValidationException::withMessages(['percent' => $e->getMessage()]);
        }

        $this->activity->record('stock.lot_cost_corrected', $lot->medicine, ['lot' => $lot->id, 'percent' => (float) $data['percent'], 'unit_cost' => $result['unit_cost']], $request->user());

        return back()->with('status', __('Purchase price corrected.'));
    }

    /** What a new percent on a lot would do, before it is saved. */
    public function lotCostPreview(Request $request, StockLot $lot): JsonResponse
    {
        $this->authorize('manage-stock');
        $data = $request->validate(['percent' => ['required', 'numeric', 'min:0', 'max:99.9']]);

        return response()->json($this->costs->preview($lot->load('medicine'), (float) $data['percent']));
    }

    /** One global switch, not per product. Turning it on is the day all goods have been entered. */
    public function masterSync(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate(['on' => ['required', 'boolean'], 'confirm' => ['sometimes', 'boolean']]);

        if ($data['on'] && ! ($data['confirm'] ?? false)) {
            throw ValidationException::withMessages(['on' => __('Make sure all goods are entered first, then confirm.')]);
        }

        $this->stock->setMasterSync((bool) $data['on']);
        $this->activity->record($data['on'] ? 'stock.master_sync_on' : 'stock.master_sync_off', null, [], $request->user());

        return back()->with('status', $data['on'] ? __('Master Stock Sync is on.') : __('Master Stock Sync is off.'));
    }
}
