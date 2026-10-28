<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReceivePaymentRequest;
use App\Models\User;
use App\Services\Ledger\Receivables;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The credit ledger (baki khata, spec ch 28 note 12): who owes us, their statement, and taking a payment. */
class LedgerController extends Controller
{
    public function __construct(private Receivables $ledger) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-reports');
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('admin/ledger/index', [
            'rows' => $this->ledger->owing($q),
            'total' => $this->ledger->totalOwed(),
            'late' => $this->ledger->late(),
            'noCustomer' => $this->ledger->withoutCustomer(),
            'overdueDays' => $this->ledger->overdueDays(),
            'q' => $q,
        ]);
    }

    public function show(User $user): Response
    {
        $this->authorize('view-reports');

        return Inertia::render('admin/ledger/show', [
            'customer' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'type' => $user->account_type->value],
            'account' => $this->ledger->statement($user),
            'overdueDays' => $this->ledger->overdueDays(),
            'methods' => Receivables::METHODS,
        ]);
    }

    public function pay(ReceivePaymentRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->ledger->receive($user, (float) $data['amount'], $data['method'], $data['note'] ?? null, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['payment' => __($e->getMessage())]);
        }

        return back()->with('status', __('Payment saved.'));
    }
}
