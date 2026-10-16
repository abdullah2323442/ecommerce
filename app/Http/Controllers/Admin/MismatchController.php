<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\StockMismatch;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\MismatchService;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Staff report a stock mismatch; the admin reads the reports and fixes the number or dismisses them (spec 6.7). */
class MismatchController extends Controller
{
    public function __construct(private MismatchService $mismatches, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/stock/mismatches', ['reports' => $this->mismatches->open()]);
    }

    /** Staff at the counter, or the admin: "found 50, the system says 60". The reply tells the reporter nothing about stock. */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(Gate::any(['use-counter', 'manage-stock']), 403);

        $data = $request->validate([
            'medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'found' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $report = $this->mismatches->report(Medicine::findOrFail($data['medicine_id']), (float) $data['found'], $request->user(), $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['found' => $e->getMessage()]);
        }

        $this->activity->record('stock.mismatch_reported', $report->medicine, ['found' => (float) $data['found']], $request->user());

        return back()->with('status', __('Reported. The admin has been told.'));
    }

    public function resolve(Request $request, StockMismatch $mismatch): RedirectResponse
    {
        $this->authorize('manage-stock');
        $data = $request->validate(['action' => ['required', Rule::in(['fix', 'dismiss'])]]);

        try {
            $this->mismatches->resolve($mismatch->load('medicine', 'reporter'), $data['action'], $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        $this->activity->record('stock.mismatch_'.($data['action'] === 'fix' ? 'fixed' : 'dismissed'), $mismatch->medicine, [], $request->user());

        return back()->with('status', $data['action'] === 'fix' ? __('Stock corrected.') : __('Report dismissed.'));
    }
}
