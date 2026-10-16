<?php

namespace App\Services\Sales;

use App\Models\ApprovalRequest;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\DB;

/**
 * The "Need admin approval" flow (spec 6.2). Staff ask, the admin answers with one tap, and the answer is applied
 * on the spot with the admin as the actor. A request nobody answers cancels itself after a set time
 * (setting `approvals.expire_minutes`, default 10) and staff carry on at the normal rate. Every approved
 * discount above the limit is recorded in the activity log with who approved it.
 */
class ApprovalService
{
    public const KINDS = ['end_discount', 'invoice_percent', 'line_price'];

    public function __construct(private InvoiceService $invoices, private ActivityLogger $activity) {}

    public function minutes(): int
    {
        return max(1, (int) Setting::read('approvals.expire_minutes', 10));
    }

    /** Staff ask. One open request per thing at a time: asking again just refreshes the clock. */
    public function request(Invoice $invoice, string $kind, float $value, User $by, ?InvoiceLine $line = null): ApprovalRequest
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new StockException('Unknown request.');
        }

        if ($by->can('decide-approvals')) {
            throw new StockException('The admin does not need approval.');
        }

        if ($value < 0) {
            throw new StockException('A discount is between 0 and 100 percent.');
        }

        $this->expireOld();

        $open = ApprovalRequest::where('invoice_id', $invoice->id)->where('kind', $kind)->where('invoice_line_id', $line?->id)->where('status', 'pending')->first();

        if ($open) {
            $open->update(['value' => $value, 'expires_at' => now()->addMinutes($this->minutes())]);

            return $open->fresh();
        }

        return DB::transaction(function () use ($invoice, $kind, $value, $by, $line) {
            $request = ApprovalRequest::create([
                'kind' => $kind, 'invoice_id' => $invoice->id, 'invoice_line_id' => $line?->id, 'requested_by' => $by->id, 'value' => $value,
                'expires_at' => now()->addMinutes($this->minutes()),
            ]);

            $this->tellAdmins($request, 'approval_request');

            return $request;
        });
    }

    /**
     * The admin says yes: the thing is applied now, as the admin, so the staff screen simply shows it done. If the
     * invoice has moved on meanwhile the request is still approved but marked as not applied.
     */
    public function approve(ApprovalRequest $request, User $admin): ApprovalRequest
    {
        $this->guardAdmin($admin);
        $request = $this->openOrFail($request);

        return DB::transaction(function () use ($request, $admin) {
            $invoice = $request->invoice;
            $applied = false;

            try {
                $applied = match ($request->kind) {
                    'end_discount' => $invoice->status === 'draft' && $invoice->kind === 'retail' && $this->invoices->setEndDiscount($invoice, (float) $request->value, $admin) !== null,
                    'invoice_percent' => $invoice->status === 'draft' && $invoice->kind !== 'retail' && $this->invoices->setInvoicePercent($invoice, (float) $request->value, $admin) !== null,
                    'line_price' => $request->line !== null && in_array($invoice->status, ['draft', 'placed'], true) && $this->invoices->setLinePrice($request->line, (float) $request->value, $admin) !== null,
                    default => false,
                };
            } catch (StockException) {
                $applied = false;
            }

            $request->update(['status' => 'approved', 'applied' => $applied, 'decided_by' => $admin->id, 'decided_at' => now()]);
            $this->activity->record('approval.approved', $invoice, ['kind' => $request->kind, 'value' => (string) $request->value, 'asked_by' => $request->requested_by, 'applied' => $applied], $admin);
            $this->tellStaff($request, 'approval_yes');

            return $request->fresh();
        });
    }

    public function reject(ApprovalRequest $request, User $admin): ApprovalRequest
    {
        $this->guardAdmin($admin);
        $request = $this->openOrFail($request);
        $request->update(['status' => 'rejected', 'decided_by' => $admin->id, 'decided_at' => now()]);
        $this->activity->record('approval.rejected', $request->invoice, ['kind' => $request->kind, 'value' => (string) $request->value, 'asked_by' => $request->requested_by], $admin);
        $this->tellStaff($request, 'approval_no');

        return $request->fresh();
    }

    /** Nothing stays hanging: open requests past their time are cancelled. Returns how many. */
    public function expireOld(): int
    {
        return ApprovalRequest::where('status', 'pending')->where('expires_at', '<', now())->update(['status' => 'expired', 'decided_at' => now()]);
    }

    public function pendingCount(): int
    {
        $this->expireOld();

        return ApprovalRequest::where('status', 'pending')->count();
    }

    /** Staff tried something past their limit (the admin hears about it, spec 6.2 "I get a notification"). */
    public function noteAttempt(User $by, string $what, ?int $invoiceId = null): void
    {
        if ($by->can('decide-approvals')) {
            return;
        }

        foreach (User::withPermission('approvals')->pluck('id') as $adminId) {
            Notice::create(['user_id' => $adminId, 'kind' => 'staff_attempt', 'body' => mb_substr($by->name.': '.$what, 0, 290)]);
        }
    }

    private function openOrFail(ApprovalRequest $request): ApprovalRequest
    {
        $this->expireOld();
        $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($request->status !== 'pending') {
            throw new StockException('This request is already closed.');
        }

        return $request;
    }

    private function guardAdmin(User $user): void
    {
        if (! $user->can('decide-approvals')) {
            throw StockException::adminOnly();
        }
    }

    private function tellAdmins(ApprovalRequest $request, string $kind): void
    {
        foreach (User::withPermission('approvals')->pluck('id') as $adminId) {
            Notice::create(['user_id' => $adminId, 'kind' => $kind, 'body' => (string) $request->id]);
        }
    }

    private function tellStaff(ApprovalRequest $request, string $kind): void
    {
        Notice::create(['user_id' => $request->requested_by, 'kind' => $kind, 'body' => (string) $request->id]);
    }
}
