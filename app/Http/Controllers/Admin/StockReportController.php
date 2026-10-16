<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Medicine;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\Bin;
use App\Services\Stock\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Stock reports, shop expenses and net profit (spec 4.3.9). Admin only. */
class StockReportController extends Controller
{
    public function __construct(private ReportService $reports, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-reports');

        $month = $this->month($request);

        return Inertia::render('admin/stock/reports', [
            'month' => $month->format('Y-m'),
            'gross' => $this->reports->gross($month),
            'net' => $this->reports->net($month),
            'expenses' => $this->reports->expenses($month)->map(fn (Expense $e) => [
                'id' => $e->id, 'spent_on' => $e->spent_on->toDateString(), 'category' => $e->category, 'amount' => (float) $e->amount,
                'note' => $e->note, 'recurring' => $e->recurring, 'recurring_until' => $e->recurring_until?->toDateString(),
            ])->values(),
            'trend' => $this->reports->trend(12, $month),
            'lowStock' => $this->reports->lowStock(),
            'finishedToday' => $this->reports->finishedToday(),
            'categories' => Expense::CATEGORIES,
        ]);
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $this->authorize('view-reports');

        $data = $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:300'],
            'recurring' => ['sometimes', 'boolean'],
            'recurring_until' => ['nullable', 'date', 'after_or_equal:spent_on'],
        ]);

        $expense = Expense::create($data + ['created_by' => $request->user()->id]);
        $this->activity->record('expense.created', $expense, $data, $request->user());

        return back()->with('status', __('Expense saved.'));
    }

    public function destroyExpense(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('view-reports');

        $this->activity->record('expense.deleted', $expense, $expense->only(['spent_on', 'category', 'amount']), $request->user());
        app(Bin::class)->keep($expense, $request->user());
        $expense->delete();

        return back()->with('status', __('Expense removed.'));
    }

    /** The level at or under which a medicine shows in the low-stock alert. Empty removes it. */
    public function reorderLevel(Request $request, Medicine $medicine): RedirectResponse
    {
        $this->authorize('view-reports');

        $data = $request->validate(['level' => ['nullable', 'numeric', 'min:0', 'max:1000000']]);
        $medicine->update(['reorder_level' => $data['level']]);

        return back()->with('status', __('Reorder level saved.'));
    }

    private function month(Request $request): Carbon
    {
        $value = (string) $request->query('month', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth() : now()->startOfMonth();
    }
}
