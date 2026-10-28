<?php

namespace App\Http\Controllers;

use App\Services\Stock\StockException;
use App\Services\Sync\PharmaTrack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What PharmaTrack calls (spec item 612): read the stock, write down its own sales, send a stock count. All JSON, all behind the bearer token. */
class PharmaTrackController extends Controller
{
    public function __construct(private PharmaTrack $sync) {}

    public function stock(Request $request): JsonResponse
    {
        return response()->json($this->sync->stockPage((int) $request->query('page', 1), (int) $request->query('per_page', 500), $request->query('updated_since') ? (string) $request->query('updated_since') : null, array_filter(explode(',', (string) $request->query('ids', '')), 'is_numeric')));
    }

    public function sales(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->sync->receiveSale($request->json()->all()));
    }

    public function counts(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->sync->receiveCount($request->json()->all()));
    }

    public function adjustments(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->sync->receiveAdjustment($request->json()->all()));
    }

    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    private function run(callable $do): JsonResponse
    {
        try {
            return response()->json($do());
        } catch (StockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
