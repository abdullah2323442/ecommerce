<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\Audit\ActivityLogger;
use App\Services\Sales\Quotes;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The price requests from trade buyers, and our answers (spec ch 14). */
class QuoteController extends Controller
{
    public function __construct(private Quotes $quotes, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('handle-orders');
        $row = fn (Quote $q) => [
            'id' => $q->id, 'buyer' => $q->user->business_name ?: $q->user->name, 'request' => $q->request, 'quantity' => $q->quantity, 'status' => $q->status,
            'total' => $q->quoted_total !== null ? (float) $q->quoted_total : null, 'note' => $q->answer_note, 'valid_until' => $q->valid_until?->toDateString(), 'at' => $q->created_at->toIso8601String(),
        ];
        $list = fn (array $status, string $by = 'id') => Quote::whereIn('status', $status)->with('user:id,name,business_name')->orderBy($by)->limit(60)->get()->map($row);

        return Inertia::render('admin/quotes', ['asking' => $list(['requested']), 'accepted' => $list(['accepted']), 'quoted' => $list(['quoted']), 'closed' => $list(['declined', 'cancelled'])]);
    }

    public function answer(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['total' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'days' => ['required', 'integer', 'min:1', 'max:60'], 'note' => ['nullable', 'string', 'max:300']]);

        try {
            $this->quotes->answer($quote->load('user'), $request->user(), (float) $data['total'], (int) $data['days'], $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['quote' => __($e->getMessage())]);
        }
        $this->activity->record('quote.answered', $quote->user, ['quote' => $quote->id, 'total' => $data['total']], $request->user());

        return back()->with('status', __('Sent to the buyer.'));
    }
}
