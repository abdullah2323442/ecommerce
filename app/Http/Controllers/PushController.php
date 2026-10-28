<?php

namespace App\Http\Controllers;

use App\Services\Push\Notifications;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A device says yes (or no) to notifications. Only the signed-in person's own devices are touched. */
class PushController extends Controller
{
    public function subscribe(Request $request, Notifications $push): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000'], 'keys' => ['required', 'array'], 'keys.p256dh' => ['required', 'string', 'max:200'], 'keys.auth' => ['required', 'string', 'max:100']]);

        if (! $push->enabled()) {
            return response()->json(['message' => __('Notifications are not switched on.')], 422);
        }

        try {
            $push->subscribe($request->user(), $data, (string) $request->userAgent());
        } catch (StockException $e) {
            return response()->json(['message' => __($e->getMessage())], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request, Notifications $push): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        $push->unsubscribe($request->user(), $data['endpoint']);

        return response()->json(['ok' => true]);
    }
}
