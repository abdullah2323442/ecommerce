<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Sales\Deliveries;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Our own delivery people (spec ch 20): their list and proof of delivery, and for the office the money they hold and how they are rated. */
class DeliveryController extends Controller
{
    public function __construct(private Deliveries $deliveries) {}

    // ------------------------------------------------------------------ the delivery person's screen

    public function index(Request $request): Response
    {
        $this->authorize('make-deliveries');

        return Inertia::render('admin/deliveries', ['groups' => $this->deliveries->list($request->user()), 'holding' => $this->deliveries->holding($request->user())]);
    }

    public function arrived(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('make-deliveries');

        return $this->attempt(fn () => $this->deliveries->arrived($invoice, $request->user()), 'The buyer is told you are there.');
    }

    public function confirm(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('make-deliveries');
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:6'], 'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'collected' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ]);
        $collected = isset($data['collected']) ? (float) $data['collected'] : null;

        if ($request->hasFile('photo')) {
            return $this->attempt(fn () => $this->deliveries->confirmWithPhoto($invoice, $request->user(), $request->file('photo'), $collected), 'Delivered. Thank you.');
        }

        if (blank($data['code'] ?? null)) {
            throw ValidationException::withMessages(['code' => __('Write the buyer\'s code, or take a photo of the parcel.')]);
        }

        return $this->attempt(fn () => $this->deliveries->confirmWithCode($invoice, $request->user(), $data['code'], $collected), 'Delivered. Thank you.');
    }

    // ------------------------------------------------------------------ the office

    public function assign(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);
        $data = $request->validate(['person_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->attempt(fn () => $this->deliveries->assign($invoice, User::findOrFail($data['person_id']), $request->user()), 'Sent with our delivery person.');
    }

    public function cash(): Response
    {
        $this->authorize('handle-orders');

        return Inertia::render('admin/delivery-cash', ['people' => $this->deliveries->holdings()]);
    }

    public function handIn(Request $request, User $person): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($person->hasPermission('deliveries'), 404);
        $amount = $this->deliveries->handIn($person, $request->user());

        return back()->with('status', __('Received {amount} from {name}.', ['amount' => number_format($amount, 2), 'name' => $person->name]));
    }

    public function ratings(): Response
    {
        $this->authorize('handle-orders');

        return Inertia::render('admin/delivery-ratings', $this->deliveries->averages());
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['delivery' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
