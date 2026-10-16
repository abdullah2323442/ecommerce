<?php

namespace App\Services\Sales;

use App\Models\ControlledSale;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The controlled-medicine sale register (spec ch 27). A sale that has a controlled medicine on it cannot be finished
 * until the buyer, the prescription number and the doctor are written down. The quantity, the date and the staff
 * member come from the sale itself. A finished entry can only be changed by someone who may see reports, and every
 * change is logged with what it was before.
 */
class ControlledRegister
{
    private const FIELDS = ['buyer_name', 'buyer_phone', 'buyer_address', 'prescription_no', 'doctor_name'];

    public function __construct(private ActivityLogger $activity) {}

    /** Live lines of this invoice that are controlled medicines, with any details already written. */
    public function lines(Invoice $invoice): Collection
    {
        return $invoice->lines()->with(['medicine:id,brand_name,strength,is_controlled', 'controlled'])->get()
            ->filter(fn (InvoiceLine $l) => $l->medicine->is_controlled)->values();
    }

    /** @return list<string> the names of controlled lines that still have no details */
    public function missing(Invoice $invoice): array
    {
        return $this->lines($invoice)
            ->filter(fn (InvoiceLine $l) => $l->controlled === null && ! $l->is_outside && (float) $l->quantity > 0)
            ->map(fn (InvoiceLine $l) => trim($l->medicine->brand_name.' '.$l->medicine->strength))->values()->all();
    }

    /** What the screens show for the controlled lines of an invoice. */
    public function panel(Invoice $invoice): array
    {
        return $this->lines($invoice)->filter(fn (InvoiceLine $l) => (float) $l->quantity > 0)->map(fn (InvoiceLine $l) => [
            'line_id' => $l->id, 'name' => trim($l->medicine->brand_name.' '.$l->medicine->strength), 'quantity' => (float) $l->quantity, 'unit' => $l->unit,
            'details' => $l->controlled?->only(self::FIELDS),
        ])->values()->all();
    }

    /**
     * Write or change the details for one line. Before the sale is finished anyone at the counter may; after it, only
     * someone who may see reports, and the old words are kept in the log.
     *
     * @param  array{buyer_name: string, buyer_phone?: ?string, buyer_address?: ?string, prescription_no: string, doctor_name: string}  $data
     */
    public function record(InvoiceLine $line, array $data, User $by): ControlledSale
    {
        $line->loadMissing('medicine', 'invoice');

        if (! $line->medicine->is_controlled) {
            throw new StockException('This medicine is not a controlled one.');
        }

        if ($line->removed_at !== null) {
            throw new StockException('This line is no longer on the memo.');
        }

        if ($line->invoice->status === 'final' && ! $by->can('view-reports')) {
            throw StockException::adminOnly();
        }

        if (blank($data['buyer_phone'] ?? null) && blank($data['buyer_address'] ?? null)) {
            throw new StockException('Write the phone or the address of the buyer.');
        }

        $existing = ControlledSale::where('invoice_line_id', $line->id)->first();
        $before = $existing?->only(self::FIELDS);

        return DB::transaction(function () use ($line, $data, $by, $existing, $before) {
            $row = ControlledSale::updateOrCreate(['invoice_line_id' => $line->id], [
                'invoice_id' => $line->invoice_id, 'medicine_id' => $line->medicine_id,
                'buyer_name' => trim($data['buyer_name']), 'buyer_phone' => $data['buyer_phone'] ?? null, 'buyer_address' => $data['buyer_address'] ?? null,
                'prescription_no' => trim($data['prescription_no']), 'doctor_name' => trim($data['doctor_name']), 'recorded_by' => $existing?->recorded_by ?? $by->id,
            ]);

            $this->activity->record($existing ? 'controlled.edited' : 'controlled.recorded', $row, $existing ? ['before' => $before, 'after' => $row->only(self::FIELDS)] : [], $by);

            return $row;
        });
    }

    /** A corrected memo is a new invoice with copied lines: the register entries go with them. */
    public function copyForward(InvoiceLine $from, InvoiceLine $to): void
    {
        $row = ControlledSale::where('invoice_line_id', $from->id)->first();

        if ($row) {
            ControlledSale::create(['invoice_id' => $to->invoice_id, 'invoice_line_id' => $to->id, 'medicine_id' => $to->medicine_id] + $row->only([...self::FIELDS, 'recorded_by']));
        }
    }

    /**
     * Every controlled sale in the period, finished memos only. A memo cancelled afterwards stays listed and is marked,
     * because the register is a record of what happened, not of what stands.
     */
    public function entries(?Carbon $from, ?Carbon $to, ?int $medicineId = null): Collection
    {
        return ControlledSale::query()
            ->join('invoices', 'invoices.id', '=', 'controlled_sales.invoice_id')
            ->join('invoice_lines', 'invoice_lines.id', '=', 'controlled_sales.invoice_line_id')
            ->join('medicines', 'medicines.id', '=', 'controlled_sales.medicine_id')
            ->leftJoin('users as staff', 'staff.id', '=', 'invoices.finalised_by')
            ->whereNotNull('invoices.number')
            ->when($from, fn ($q) => $q->where('invoices.finalised_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('invoices.finalised_at', '<=', $to->copy()->endOfDay()))
            ->when($medicineId, fn ($q) => $q->where('controlled_sales.medicine_id', $medicineId))
            ->orderBy('invoices.finalised_at')->orderBy('controlled_sales.id')
            ->get([
                'controlled_sales.id', 'controlled_sales.invoice_line_id', 'controlled_sales.buyer_name', 'controlled_sales.buyer_phone', 'controlled_sales.buyer_address',
                'controlled_sales.prescription_no', 'controlled_sales.doctor_name', 'invoices.number', 'invoices.finalised_at', 'invoices.status as invoice_status',
                'invoice_lines.quantity', 'invoice_lines.unit', 'medicines.brand_name', 'medicines.strength', 'staff.name as staff_name',
            ]);
    }
}
