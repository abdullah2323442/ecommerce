<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Couriers\CourierBooking;
use App\Services\Integrations\Integrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A courier company telling us where a parcel is. Open to the world, so it is strict: it works only for a company that is switched on and has a
 * password saved here, the password must come in the Authorization header, and it can only change the progress of a parcel we booked (found by the
 * company's own number AND our invoice reference together). Nothing the sender writes is ever shown to anyone.
 */
class CourierWebhookController extends Controller
{
    public function receive(Request $request, string $provider, Integrations $integrations, CourierBooking $booking): JsonResponse
    {
        $on = $integrations->active("courier:{$provider}");
        $token = (string) ($on['credentials']['webhook_token'] ?? '');

        // Not set up: as if there were no such address.
        if (! $on || $token === '') {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! hash_equals('Bearer '.$token, (string) $request->header('Authorization'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate(['consignment_id' => ['required'], 'status' => ['required', 'string', 'max:40'], 'invoice' => ['nullable', 'string', 'max:60']]);
        $order = Invoice::where('courier_consignment', (string) $data['consignment_id'])->first();

        // A parcel that is not ours (or whose invoice reference does not match ours) is ignored, politely, so the company stops resending it.
        if (! $order || (! empty($data['invoice']) && $data['invoice'] !== 'ORD'.$order->id)) {
            return response()->json(['message' => 'Ignored.']);
        }

        $booking->apply($order->loadMissing('courier'), (string) $data['status']);

        return response()->json(['message' => 'Received.']);
    }
}
