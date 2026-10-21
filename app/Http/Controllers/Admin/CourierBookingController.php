<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Couriers\CourierBooking;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\PathaoProvider;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff book a parcel with the courier company and ask where it is (spec ch 20). */
class CourierBookingController extends Controller
{
    public function __construct(private CourierBooking $booking) {}

    public function book(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);

        $place = $request->validate(['city_id' => ['nullable', 'integer', 'min:1'], 'zone_id' => ['nullable', 'integer', 'min:1']]);

        return $this->attempt(fn () => $this->booking->book($invoice, $request->user(), $place), 'Booked. The buyer is told it is on the way.');
    }

    /** Pathao's cities, the zones of one city, and the guess for this order's address. */
    public function places(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);
        $invoice->loadMissing('courier');
        abort_unless($invoice->courier?->provider === 'pathao', 404);

        try {
            $pathao = app(PathaoProvider::class);
            $ship = (array) $invoice->ship_to;
            $guess = $pathao->match((string) ($ship['district'] ?? ''), (string) ($ship['area'] ?? ''));
            $city = (int) $request->integer('city', $guess['city_id'] ?? 0);

            return response()->json(['cities' => $pathao->cities(), 'zones' => $city > 0 ? $pathao->zones($city) : [], 'city_id' => $city ?: null, 'zone_id' => $city === ($guess['city_id'] ?? 0) ? ($guess['zone_id'] ?? null) : null]);
        } catch (CourierException $e) {
            return response()->json(['message' => __($e->getMessage())], 422);
        }
    }

    public function refresh(Invoice $invoice): RedirectResponse
    {
        $this->authorize('handle-orders');
        abort_unless($invoice->channel === 'online', 404);

        return $this->attempt(fn () => $this->booking->refresh($invoice->loadMissing('courier')), 'Updated.');
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['courier' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
