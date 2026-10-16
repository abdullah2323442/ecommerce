<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ControlledSale;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Services\Sales\ControlledRegister;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The controlled-medicine register (spec ch 27): written at the counter, read and printed by the admin and sub-admin. */
class ControlledController extends Controller
{
    public function __construct(private ControlledRegister $register) {}

    /** Details for one controlled line. Counter and order staff write them; a finished entry only changes for someone who sees reports. */
    public function store(Request $request, InvoiceLine $line): RedirectResponse
    {
        $user = $request->user();
        if (! ($user->can('use-counter') || $user->can('handle-orders') || $user->can('view-reports'))) {
            throw new AuthorizationException;
        }

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'buyer_address' => ['nullable', 'string', 'max:250'],
            'prescription_no' => ['required', 'string', 'max:60'],
            'doctor_name' => ['required', 'string', 'max:120'],
        ]);

        try {
            $this->register->record($line, $data, $user);
        } catch (StockException $e) {
            return back()->withErrors(['controlled' => __($e->getMessage())]);
        }

        return back()->with('status', __('Saved in the register.'));
    }

    public function index(Request $request): Response
    {
        $this->authorize('view-reports');
        [$from, $to, $medicine] = $this->filters($request);

        return Inertia::render('admin/controlled', [
            'entries' => $this->rows($this->register->entries($from, $to, $medicine)),
            'filters' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'medicine' => $medicine],
            'medicines' => $this->medicines(),
        ]);
    }

    /** A plain page for the inspector: no menus, prints on its own. */
    public function print(Request $request): Response
    {
        $this->authorize('view-reports');
        [$from, $to, $medicine] = $this->filters($request);

        return Inertia::render('admin/controlled-print', [
            'entries' => $this->rows($this->register->entries($from, $to, $medicine)),
            'filters' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('view-reports');
        [$from, $to, $medicine] = $this->filters($request);
        $rows = $this->register->entries($from, $to, $medicine);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Memo', 'Medicine', 'Strength', 'Quantity', 'Unit', 'Buyer', 'Phone', 'Address', 'Prescription no', 'Doctor', 'Staff', 'Memo cancelled']);

            foreach ($rows as $r) {
                fputcsv($out, [$r->finalised_at, $r->number, $r->brand_name, $r->strength, (float) $r->quantity, $r->unit, $r->buyer_name, $r->buyer_phone, $r->buyer_address, $r->prescription_no, $r->doctor_name, $r->staff_name, $r->invoice_status === 'cancelled' ? 'yes' : '']);
            }
            fclose($out);
        }, 'controlled-register.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon, 2: ?int} */
    private function filters(Request $request): array
    {
        $date = function (string $key, ?Carbon $default): ?Carbon {
            try {
                return request()->query($key) ? Carbon::createFromFormat('Y-m-d', (string) request()->query($key)) : $default;
            } catch (\Throwable) {
                return $default;
            }
        };

        return [$date('from', now()->startOfMonth()), $date('to', now()), $request->integer('medicine') ?: null];
    }

    private function medicines(): array
    {
        return Medicine::whereIn('id', ControlledSale::query()->select('medicine_id'))->orderBy('brand_name')->get(['id', 'brand_name', 'strength'])
            ->map(fn ($m) => ['id' => $m->id, 'name' => trim($m->brand_name.' '.$m->strength)])->values()->all();
    }

    private function rows($entries): array
    {
        return $entries->map(fn ($r) => [
            'id' => $r->id, 'line_id' => $r->invoice_line_id, 'when' => Carbon::parse($r->finalised_at)->toIso8601String(), 'memo' => $r->number, 'medicine' => trim($r->brand_name.' '.$r->strength),
            'quantity' => (float) $r->quantity, 'unit' => $r->unit, 'buyer_name' => $r->buyer_name, 'buyer_phone' => $r->buyer_phone, 'buyer_address' => $r->buyer_address,
            'prescription_no' => $r->prescription_no, 'doctor_name' => $r->doctor_name, 'staff' => $r->staff_name, 'cancelled' => $r->invoice_status === 'cancelled',
        ])->values()->all();
    }
}
