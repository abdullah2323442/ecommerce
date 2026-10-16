<?php

namespace App\Services\Sales;

use App\Models\Notice;
use App\Models\Quote;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;

/**
 * Custom quotation (spec ch 14, Phase 2). A trade buyer describes what they want and how much; we answer with one total and a last
 * day it holds. They accept or decline. Accepting does not place an order by itself: the staff are told, and place it at the quoted price.
 */
class Quotes
{
    public function __construct(private Messenger $messages) {}

    /** @throws StockException */
    public function request(User $buyer, string $what, ?string $quantity): Quote
    {
        if (mb_strlen(trim($what)) < 3) {
            throw new StockException('Say what you want a price for.');
        }

        if (Quote::where('user_id', $buyer->id)->where('status', 'requested')->count() >= 5) {
            throw new StockException('You have five waiting already. Please wait for our answer.');
        }

        $quote = Quote::create(['user_id' => $buyer->id, 'request' => mb_substr(trim($what), 0, 500), 'quantity' => $quantity !== null && trim($quantity) !== '' ? mb_substr(trim($quantity), 0, 60) : null, 'status' => 'requested']);
        $this->tellStaff($buyer, 'asks for a price');

        return $quote;
    }

    /** @throws StockException */
    public function answer(Quote $quote, User $by, float $total, int $validDays, ?string $note = null): Quote
    {
        if (! in_array($quote->status, ['requested', 'quoted'], true)) {
            throw new StockException('This quotation is already settled.');
        }

        if ($total <= 0) {
            throw new StockException('The price must be more than zero.');
        }

        $quote->update([
            'status' => 'quoted', 'quoted_total' => $total, 'valid_until' => today()->addDays(max(1, min(60, $validDays))), 'answer_note' => $note ? mb_substr(trim($note), 0, 300) : null,
            'answered_by' => $by->id, 'answered_at' => now(),
        ]);
        $this->messages->send($quote->user, 'quote_answered', ['name' => mb_substr($quote->request, 0, 60)], '/wholesale/quotes');

        return $quote->fresh();
    }

    /** @param 'accept'|'decline' $answer */
    public function respond(Quote $quote, User $buyer, string $answer): Quote
    {
        if ($quote->user_id !== $buyer->id) {
            throw new StockException('This is not your quotation.');
        }

        if ($quote->status !== 'quoted') {
            throw new StockException('There is nothing to answer on this quotation.');
        }

        if ($answer === 'accept') {
            if (! $quote->isOpenToAccept()) {
                throw new StockException('This price has run out. Ask again.');
            }
            $quote->update(['status' => 'accepted', 'responded_at' => now()]);
            $this->tellStaff($buyer, 'accepted our price');
        } else {
            $quote->update(['status' => 'declined', 'responded_at' => now()]);
        }

        return $quote->fresh();
    }

    public function cancel(Quote $quote, User $buyer): Quote
    {
        if ($quote->user_id !== $buyer->id || ! in_array($quote->status, ['requested', 'quoted'], true)) {
            throw new StockException('This quotation cannot be cancelled.');
        }

        $quote->update(['status' => 'cancelled', 'responded_at' => now()]);

        return $quote->fresh();
    }

    private function tellStaff(User $buyer, string $what): void
    {
        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'quote', 'body' => mb_substr(($buyer->business_name ?: $buyer->name).' '.__($what), 0, 290)]);
        }
    }
}
