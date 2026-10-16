<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockLot;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\ExpiryService;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The expiry screen (spec 4.3.7): what to check, what is close to its date, and the expired section. Admin only. */
class ExpiryController extends Controller
{
    public function __construct(private ExpiryService $expiry, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/stock/expiry', [
            'needsChecking' => $this->expiry->needsChecking(),
            'moneyAtRisk' => $this->expiry->moneyAtRisk(),
            'nearExpiry' => $this->expiry->nearExpiry(),
            'expired' => $this->expiry->expiredByCompany(),
            'settings' => collect(ExpiryService::DEFAULTS)->mapWithKeys(fn ($d, $k) => [$k => $this->expiry->setting($k)]),
        ]);
    }

    public function checked(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')],
            'expiry_date' => ['nullable', 'date', 'after:today'],
        ]);

        $n = $this->expiry->markChecked((int) $data['medicine_id'], $data['expiry_date'] ?? null);
        $this->activity->record('stock.checked', null, ['medicine_id' => $data['medicine_id'], 'lots' => $n, 'expiry_date' => $data['expiry_date'] ?? null], $request->user());

        return back()->with('status', __('Marked as checked.'));
    }

    public function expire(Request $request, StockLot $lot): RedirectResponse
    {
        $this->authorize('manage-stock');

        try {
            $this->expiry->expire($lot, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['lot' => $e->getMessage()]);
        }

        $this->activity->record('stock.expired', null, ['lot_id' => $lot->id, 'medicine_id' => $lot->medicine_id], $request->user());

        return back()->with('status', __('Moved to the expired section.'));
    }

    public function returnStatus(Request $request, StockLot $lot): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate(['status' => ['required', Rule::in(ExpiryService::RETURN_STATES)]]);

        try {
            $this->expiry->setReturnStatus($lot, $data['status']);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        $this->activity->record('stock.return_status', null, ['lot_id' => $lot->id, 'status' => $data['status']], $request->user());

        return back()->with('status', __('Saved.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->authorize('manage-stock');

        $data = $request->validate([
            'stagnant_months' => ['required', 'integer', 'min:1', 'max:60'],
            'months_of_stock_limit' => ['required', 'integer', 'min:1', 'max:120'],
            'near_expiry_months' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        $this->expiry->saveSettings($data);

        return back()->with('status', __('Saved.'));
    }
}
