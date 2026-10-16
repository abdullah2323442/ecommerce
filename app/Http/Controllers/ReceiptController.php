<?php

namespace App\Http\Controllers;

use App\Services\Sales\Receipts;
use Inertia\Inertia;
use Inertia\Response;

/** A receipt for any order, opened from the order's own private link (spec ch 22). */
class ReceiptController extends Controller
{
    public function __construct(private Receipts $receipts) {}

    public function show(string $kind, string $token): Response
    {
        $receipt = in_array($kind, Receipts::KINDS, true) ? $this->receipts->for($kind, $token) : null;
        abort_if($receipt === null, 404);

        return Inertia::render('receipt', ['receipt' => $receipt]);
    }
}
