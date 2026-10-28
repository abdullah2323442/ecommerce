<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyPaymentRequest;
use App\Models\StockSource;
use App\Services\Ledger\Payables;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** What we owe each company or supplier and the day it is due (spec ch 28 note 12). Purchase costs are the owner's alone. */
class PayablesController extends Controller
{
    public function __construct(private Payables $ledger) {}

    public function index(): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/payables/index', ['rows' => $this->ledger->owing(), 'total' => $this->ledger->totalOwed(), 'late' => $this->ledger->late()]);
    }

    public function show(StockSource $source): Response
    {
        $this->authorize('manage-stock');

        return Inertia::render('admin/payables/show', [
            'source' => ['id' => $source->id, 'name' => $source->name, 'type' => $source->type],
            'account' => $this->ledger->statement($source),
            'methods' => Payables::METHODS,
        ]);
    }

    public function pay(CompanyPaymentRequest $request, StockSource $source): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->ledger->pay($source, (float) $data['amount'], $data['method'], $data['note'] ?? null, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['payment' => __($e->getMessage())]);
        }

        return back()->with('status', __('Payment saved.'));
    }
}
