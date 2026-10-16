<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Audit\ActivityLogger;
use App\Services\Sales\EndOfDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * End-of-day cash reconciliation. Takings by payment method for the day's finished sales, the cash the till
 * should hold, and the cash actually counted. Admin only: the figures are sales totals.
 */
class DayClosingController extends Controller
{
    public function __construct(private ActivityLogger $activity, private EndOfDay $endOfDay) {}

    /** The list of what is still open before closing, for counter staff who do not read the day's figures. */
    public function checklist(Request $request): Response
    {
        abort_unless($request->user()->can('use-counter') || $request->user()->can('view-reports'), 403);

        return Inertia::render('admin/end-of-day', ['unresolved' => $this->endOfDay->unresolved($request->user())['items']]);
    }

    public function index(Request $request): Response
    {
        $this->authorize('view-reports');
        $day = $this->day($request->query('day'));

        return Inertia::render('admin/day', $this->figures($day) + [
            'day' => $day->toDateString(),
            'isToday' => $day->isToday(),
            'unresolved' => $this->endOfDay->unresolved($request->user())['items'],
            'closing' => DB::table('day_closings')->where('day', $day->toDateString())->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('view-reports');
        $data = $request->validate([
            'day' => ['required', 'date', 'before_or_equal:today'],
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $day = $this->day($data['day']);
        $expected = $this->figures($day)['expectedCash'];
        $counted = round((float) $data['counted_cash'], 2);

        DB::table('day_closings')->updateOrInsert(['day' => $day->toDateString()], [
            'expected_cash' => $expected, 'counted_cash' => $counted, 'difference' => round($counted - $expected, 2),
            'note' => $data['note'] ?? null, 'closed_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->activity->record('day.closed', null, ['day' => $day->toDateString(), 'difference' => round($counted - $expected, 2)], $request->user());

        return back()->with('status', __('Day saved.'));
    }

    private function day(mixed $value): Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value)->startOfDay() : today();
        } catch (\Throwable) {
            return today();
        }
    }

    /** @return array{methods: list<array{method: string, count: int, total: float, paid: float}>, expectedCash: float, due: float, sales: float} */
    private function figures(Carbon $day): array
    {
        $rows = Invoice::query()->where('status', 'final')->whereDate('finalised_at', $day)
            ->selectRaw('payment_method as method, count(*) as n, sum(total) as total, sum(paid_amount) as paid')
            ->groupBy('payment_method')->get();

        $methods = $rows->map(fn ($r) => ['method' => $r->method ?? 'cash', 'count' => (int) $r->n, 'total' => (float) $r->total, 'paid' => (float) $r->paid])->values()->all();
        $cash = (float) ($rows->firstWhere('method', 'cash')->paid ?? 0);
        $sales = round((float) $rows->sum('total'), 2);

        return ['methods' => $methods, 'expectedCash' => round($cash, 2), 'sales' => $sales, 'due' => round($sales - (float) $rows->sum('paid'), 2)];
    }
}
