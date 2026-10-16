<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CraftsmanPayment;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Custom\Craftsmen;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Our own craftsmen: their accounts, how many jobs each is on, what is owed and what is paid (spec ch 16.4, 16.7). Only the owner opens accounts. */
class CraftsmanController extends Controller
{
    public function __construct(private Craftsmen $craftsmen, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('handle-orders');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? (string) $request->query('month') : now()->format('Y-m');

        return Inertia::render('admin/craftsmen', [
            'month' => $month, 'canOpen' => $request->user()->can('manage-accounts'),
            'craftsmen' => $this->craftsmen->all()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'closed' => $u->isSuspended(), 'active_jobs' => $this->craftsmen->activeJobs($u),
                'ledger' => $this->craftsmen->ledger($u, $month),
                'payments' => CraftsmanPayment::where('craftsman_id', $u->id)->latest('id')->limit(5)->get()->map(fn ($p) => ['kind' => $p->kind, 'amount' => (float) $p->amount, 'note' => $p->note, 'on' => $p->paid_on->toDateString()]),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'], 'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'max:100'],
        ]);
        $this->craftsmen->open($data, $request->user());

        return back()->with('status', __('Account opened.'));
    }

    public function pay(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'note' => ['nullable', 'string', 'max:200']]);

        try {
            $this->craftsmen->pay($user, $request->user(), (float) $data['amount'], $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['craftsman' => __($e->getMessage())]);
        }
        $this->activity->record('craftsman.paid', $user, ['amount' => $data['amount']], $request->user());

        return back()->with('status', __('Noted.'));
    }
}
