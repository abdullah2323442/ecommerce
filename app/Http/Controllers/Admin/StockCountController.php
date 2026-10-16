<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\StockCount;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockCountService;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Stock count sessions and barcode linking (spec CNT-1, CNT-2, 4.3.8). Admin only. */
class StockCountController extends Controller
{
    public function __construct(private StockCountService $counts, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/stock/counts', [
            'counts' => StockCount::with('company:id,name')->latest('id')->limit(30)->get()->map(fn (StockCount $c) => [
                'id' => $c->id, 'scope' => $c->scope, 'company' => $c->company?->name, 'shelf' => $c->shelf, 'status' => $c->status,
                'started_at' => $c->started_at?->toDateString(), 'difference_value' => (float) $c->difference_value,
            ]),
            'companies' => $this->counts->companies(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'scope' => ['required', Rule::in(['all', 'company', 'shelf'])],
            'company_id' => ['required_if:scope,company', 'nullable', 'integer', Rule::exists('companies', 'id')],
            'shelf' => ['required_if:scope,shelf', 'nullable', 'string', 'max:40'],
        ]);

        $count = $this->counts->start($data, $request->user());
        $this->activity->record('stock.count_started', null, ['count_id' => $count->id, 'scope' => $data['scope']], $request->user());

        return to_route('admin.stock.counts.show', $count);
    }

    public function show(StockCount $count): Response
    {
        $this->authorize('manage-stock');

        $review = $this->counts->review($count);

        return Inertia::render('admin/stock/count', [
            'count' => ['id' => $count->id, 'scope' => $count->scope, 'shelf' => $count->shelf, 'status' => $count->status, 'started_at' => $count->started_at?->toDateTimeString(), 'difference_value' => (float) $count->difference_value],
            'lines' => $review,
            'totalDifference' => round((float) $review->sum('difference_value'), 2),
        ]);
    }

    public function record(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'counted' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);

        try {
            $this->counts->record($count, Medicine::findOrFail($data['medicine_id']), (float) $data['counted']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['counted' => $e->getMessage()]);
        }

        return back();
    }

    /** A barcode scan: counts one more of the linked medicine. An unknown code answers 422 so the screen can ask which medicine it is. */
    public function scan(Request $request, StockCount $count): RedirectResponse|JsonResponse
    {
        $this->authorize('manage-stock');

        $code = MedicineBarcode::clean((string) $request->validate(['code' => ['required', 'string', 'max:64']])['code']);
        $barcode = MedicineBarcode::with('medicine')->where('code', $code)->first();

        if (! $barcode) {
            throw ValidationException::withMessages(['code' => __('Unknown barcode. Pick the medicine once and it is remembered.')]);
        }

        try {
            $this->counts->scan($count, $barcode->medicine);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return back()->with('status', __(':name counted.', ['name' => $barcode->medicine->brand_name]));
    }

    public function pause(StockCount $count): RedirectResponse
    {
        $this->authorize('manage-stock');
        $this->counts->pause($count);

        return back();
    }

    public function cancel(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('manage-stock');
        $this->counts->cancel($count);
        $this->activity->record('stock.count_cancelled', null, ['count_id' => $count->id], $request->user());

        return to_route('admin.stock.counts');
    }

    public function approve(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('manage-stock');

        try {
            $done = $this->counts->approve($count, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['approve' => $e->getMessage()]);
        }

        $this->activity->record('stock.count_approved', null, ['count_id' => $count->id, 'difference_value' => (string) $done->difference_value], $request->user());

        return to_route('admin.stock.counts')->with('status', __('Count approved and stock adjusted.'));
    }

    /** Link a barcode to a medicine once; it is remembered for good. Several codes per medicine are fine. */
    public function linkBarcode(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
        ]);

        $code = MedicineBarcode::clean($data['code']);
        if ($code === '' || MedicineBarcode::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('That barcode is already linked.')]);
        }

        MedicineBarcode::create(['medicine_id' => $data['medicine_id'], 'code' => $code]);
        $this->activity->record('stock.barcode_linked', Medicine::find($data['medicine_id']), ['code' => $code], $request->user());

        return back()->with('status', __('Barcode linked.'));
    }

    /** Look a code up without side effects (for the purchase screen). */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('manage-stock');

        $code = MedicineBarcode::clean((string) $request->query('code', ''));
        $barcode = $code === '' ? null : MedicineBarcode::with('medicine.company:id,name')->where('code', $code)->first();

        return $barcode
            ? response()->json(['medicine' => ['id' => $barcode->medicine->id, 'brand_name' => $barcode->medicine->brand_name, 'strength' => $barcode->medicine->strength, 'form' => $barcode->medicine->form, 'company' => ['name' => $barcode->medicine->company?->name]]])
            : response()->json(['medicine' => null], 404);
    }
}
