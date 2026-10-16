<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\OfflineCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The counter that keeps working without internet (spec ch 8). Staff and admin only; retail sales only. */
class OfflineCounterController extends Controller
{
    public function __construct(private OfflineCounter $offline) {}

    /** The screen. It is saved on the device while online, so it can be opened with the line down. */
    public function page(): Response
    {
        $this->authorize('use-counter');

        return Inertia::render('admin/counter/offline');
    }

    /** A copy of the medicine list and prices for the device. `?since=` the time of the copy it already has: nothing new is sent if none changed. */
    public function snapshot(Request $request): JsonResponse
    {
        $this->authorize('use-counter');

        $snapshot = $this->offline->snapshot($request->user());

        return response()->json($snapshot)->header('Cache-Control', 'no-store');
    }

    /** The sales made offline, sent when the line is back. Each is answered with its real number, or why it was not recorded. */
    public function sync(Request $request): JsonResponse
    {
        $this->authorize('use-counter');

        $data = $request->validate([
            'sales' => ['required', 'array', 'min:1', 'max:50'],
            'sales.*.ref' => ['required', 'string', 'max:80'],
            'sales.*.at' => ['required', 'date'],
            'sales.*.lines' => ['required', 'array', 'min:1', 'max:100'],
            'sales.*.lines.*.id' => ['required', 'integer'],
            'sales.*.lines.*.unit' => ['required', Rule::in(InvoiceService::UNITS)],
            'sales.*.lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'sales.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sales.*.method' => ['nullable', Rule::in(InvoiceService::PAYMENT_METHODS)],
            'sales.*.paid' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['results' => $this->offline->sync($request->user(), $data['sales'])]);
    }
}
