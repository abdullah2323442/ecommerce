<?php

namespace App\Http\Controllers;

use App\Services\Location\LocationException;
use App\Services\Location\Locator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Use my location" on the address form: two numbers in, the district, area and street out. Nothing is saved here. */
class LocationController extends Controller
{
    public function lookup(Request $request, Locator $locator): JsonResponse
    {
        $v = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        try {
            return response()->json($locator->reverse((float) $v['lat'], (float) $v['lng']));
        } catch (LocationException $e) {
            return response()->json(['message' => __($e->getMessage())], 422);
        }
    }
}
