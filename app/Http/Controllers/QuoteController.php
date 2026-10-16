<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Services\Sales\Quotes;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A trade buyer asks for a price and answers ours (spec ch 14: custom quotation). The wholesale gate keeps everyone else out. */
class QuoteController extends Controller
{
    public function __construct(private Quotes $quotes) {}

    public function index(Request $request): Response
    {
        return Inertia::render('wholesale/quotes', [
            'quotes' => Quote::where('user_id', $request->user()->id)->latest('id')->limit(50)->get()->map(fn (Quote $q) => [
                'id' => $q->id, 'request' => $q->request, 'quantity' => $q->quantity, 'status' => $q->status, 'total' => $q->quoted_total !== null ? (float) $q->quoted_total : null,
                'note' => $q->answer_note, 'valid_until' => $q->valid_until?->toDateString(), 'can_accept' => $q->isOpenToAccept(), 'expired' => $q->status === 'quoted' && ! $q->isOpenToAccept(),
                'at' => $q->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['request' => ['required', 'string', 'min:3', 'max:500'], 'quantity' => ['nullable', 'string', 'max:60']]);

        return $this->act(fn () => $this->quotes->request($request->user(), $data['request'], $data['quantity'] ?? null), 'Sent. We will answer with a price.');
    }

    public function respond(Request $request, Quote $quote): RedirectResponse
    {
        $data = $request->validate(['answer' => ['required', 'in:accept,decline']]);

        return $this->act(fn () => $this->quotes->respond($quote, $request->user(), $data['answer']), $data['answer'] === 'accept' ? 'Thank you. We will place the order at this price.' : 'Noted.');
    }

    public function cancel(Request $request, Quote $quote): RedirectResponse
    {
        return $this->act(fn () => $this->quotes->cancel($quote, $request->user()), 'Cancelled.');
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['quote' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
