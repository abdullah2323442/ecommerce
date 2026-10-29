<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\MarketCase;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Payments\Payments;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Complaints and returns on market orders (spec ch 14). A buyer complains or sends something back; the seller sees it and solves it, and we
 * get a copy. A return goes straight to the seller, so there is no delivery cost to us and no commission on that order. What a seller cannot
 * or will not solve, or leaves unanswered, comes to us to decide.
 */
class MarketCases
{
    public function __construct(private Messenger $messages, private Statements $statements) {}

    /** Our default number of days, or the seller's own for that product (spec ch 15). */
    public function returnDays(?MarketOrder $order = null): int
    {
        $own = $order?->listing?->return_days;

        return $own !== null ? max(1, (int) $own) : max(1, (int) Setting::read('market.return_days', 7));
    }

    /** What the buyer may do with this order now. */
    public function allowed(MarketOrder $order): array
    {
        $busy = fn (string $kind) => MarketCase::where('market_order_id', $order->id)->where('kind', $kind)->whereIn('status', MarketCase::OPEN)->exists();
        $sent = in_array($order->status, ['shipped', 'delivered'], true);
        $window = $order->status === 'delivered' && $order->delivered_at !== null && $order->delivered_at->gte(now()->subDays($this->returnDays($order)));

        // Nothing can be sent back that was downloaded.
        $digital = $order->download_max > 0;

        // A card, leaflet or page address in the package is its own kind of complaint (spec ch 14: no promotion in packages), so it is counted against the seller by itself.
        return ['complaint' => $sent && ! $busy('complaint'), 'return' => $window && ! $digital && ! $busy('return'), 'promo' => $sent && ! $busy('promo')];
    }

    /** @throws StockException */
    public function open(MarketOrder $order, string $kind, string $reason): MarketCase
    {
        $reason = trim($reason);

        if (! in_array($kind, ['complaint', 'return', 'promo'], true)) {
            throw new StockException('Choose a complaint or a return.');
        }

        if (mb_strlen($reason) < 3) {
            throw new StockException('Write what went wrong.');
        }

        if (! $this->allowed($order)[$kind]) {
            throw new StockException($kind === 'return' ? 'This order cannot be returned now.' : 'You cannot complain about this order now.');
        }

        $case = MarketCase::create(['market_order_id' => $order->id, 'seller_id' => $order->seller_id, 'buyer_id' => $order->buyer_id, 'kind' => $kind, 'reason' => mb_substr($reason, 0, 300), 'status' => 'open']);

        $this->messages->send($order->seller, 'market_case_new', ['name' => $order->title], '/wholesale/sell/orders');
        $this->copyToUs($order->seller, __(match ($kind) { 'return' => 'wants to return', 'promo' => 'was reported for promotion material in the package of', default => 'complained about' }).' "'.$order->title.'"');

        return $case;
    }

    /**
     * The seller answers: solved it, takes the goods back, or cannot solve it (which brings it to us).
     *
     * @param  'solved'|'return'|'cannot'  $action
     */
    public function answer(MarketCase $case, User $seller, string $action, ?string $note = null): MarketCase
    {
        if ($case->seller_id !== $seller->id) {
            throw new StockException('This is not your case.');
        }

        if ($case->status !== 'open') {
            throw new StockException('This was already answered.');
        }

        if (! in_array($action, ['solved', 'return', 'cannot'], true)) {
            throw new StockException('Choose what you did.');
        }

        if ($action === 'return' && $case->kind === 'promo') {
            throw new StockException('Nothing is sent back for this. Say what you did.');
        }

        if ($action !== 'return' && ($note === null || mb_strlen(trim($note)) < 3)) {
            throw new StockException('Write a few words for the buyer.');
        }

        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null;

        if ($action === 'return') {
            $this->takeBack($case, ['seller_note' => $note, 'answered_at' => now()]);
        } elseif ($action === 'solved') {
            $case->update(['status' => 'solved', 'seller_note' => $note, 'answered_at' => now()]);
        } else {
            $case->update(['status' => 'escalated', 'seller_note' => $note, 'answered_at' => now(), 'escalated_at' => now()]);
            $this->copyToUs($seller, __('could not solve a case').': "'.$case->order->title.'"');
        }

        $this->tellBuyer($case->fresh());

        return $case->fresh();
    }

    /** The buyer is not satisfied, or the seller said nothing: it comes to us. */
    public function escalate(MarketCase $case): MarketCase
    {
        if (! in_array($case->status, ['open', 'solved'], true)) {
            throw new StockException('This is already with us.');
        }

        $case->update(['status' => 'escalated', 'escalated_at' => now()]);
        $this->copyToUs($case->seller, __('a case was brought to us').': "'.$case->order->title.'"');

        return $case->fresh();
    }

    /** A case the seller left unanswered for as long as a statement may wait comes to us. Returns how many. */
    public function escalateSilent(): int
    {
        $n = 0;

        MarketCase::where('status', 'open')->where('created_at', '<=', now()->subDays($this->statements->replyDays()))->with(['order', 'seller'])->each(function (MarketCase $c) use (&$n) {
            $c->update(['status' => 'escalated', 'escalated_at' => now()]);
            $this->copyToUs($c->seller, __('did not answer a case').': "'.$c->order->title.'"');
            $n++;
        });

        return $n;
    }

    /**
     * We decide when it is not solved: the goods go back, or the case is closed. Either way both sides are told.
     *
     * @param  'return'|'close'  $decision
     */
    public function decide(MarketCase $case, User $by, string $decision, string $note): MarketCase
    {
        if (! in_array($case->status, ['open', 'escalated', 'solved'], true)) {
            throw new StockException('This case is already settled.');
        }

        if (! in_array($decision, ['return', 'close'], true)) {
            throw new StockException('Choose to send it back or close the case.');
        }

        if ($decision === 'return' && $case->kind === 'promo') {
            throw new StockException('Nothing is sent back for this. Close the case, and use a penalty step if it is true.');
        }

        $meta = ['our_note' => mb_substr(trim($note), 0, 300), 'decided_at' => now(), 'decided_by' => $by->id];

        $decision === 'return' ? $this->takeBack($case, $meta) : $case->update($meta + ['status' => 'closed']);
        $case = $case->fresh();

        $this->tellBuyer($case);
        $this->messages->send($case->seller, 'market_case_answered', ['name' => $case->order->title], '/wholesale/sell/orders');

        return $case;
    }

    /** The goods go straight back to the seller: the order is no longer a complete sale (no commission), and what was held goes back on the shelf. */
    private function takeBack(MarketCase $case, array $extra): void
    {
        $order = $case->order;

        if (! in_array($order->status, ['shipped', 'delivered'], true)) {
            throw new StockException('This order cannot be returned now.');
        }

        DB::transaction(function () use ($case, $order, $extra) {
            $order->update(['status' => 'returned', 'returned_at' => now()]);
            app(Payments::class)->marketCancelled($order);
            $case->update($extra + ['status' => 'returned']);

            if ($order->listing_id && ($listing = Listing::whereKey($order->listing_id)->lockForUpdate()->first()) && $listing->stock !== null) {
                $listing->update(['stock' => round((float) $listing->stock + (float) $order->quantity, 2)]);
            }
        });
    }

    // ------------------------------------------------------------------ what each side sees

    /** @return array<string, mixed> */
    public function present(MarketCase $case): array
    {
        return [
            'id' => $case->id, 'kind' => $case->kind, 'reason' => $case->reason, 'status' => $case->status, 'seller_note' => $case->seller_note, 'our_note' => $case->our_note,
            'at' => $case->created_at->toIso8601String(), 'can_escalate' => in_array($case->status, ['open', 'solved'], true),
        ];
    }

    /** A seller's record: how much sold, how much came back, and why (spec ch 14: sales go to their account, so do returns). */
    public function record(User $seller): array
    {
        $sold = (float) MarketOrder::where('seller_id', $seller->id)->where('status', 'delivered')->sum('quantity');
        $back = MarketOrder::where('seller_id', $seller->id)->where('status', 'returned');

        return [
            'sold' => round($sold, 2), 'returned' => round((float) (clone $back)->sum('quantity'), 2), 'returned_orders' => (clone $back)->count(),
            'complaints' => MarketCase::where('seller_id', $seller->id)->where('kind', 'complaint')->count(),
            'promos' => MarketCase::where('seller_id', $seller->id)->where('kind', 'promo')->count(),
            'reasons' => MarketCase::where('seller_id', $seller->id)->where('status', 'returned')->latest('id')->limit(8)->pluck('reason')->all(),
        ];
    }

    /**
     * How many complaints and returns stand against each seller, with the serious ones (brought to us) kept in view.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bySeller(): Collection
    {
        return MarketCase::with('seller:id,name,business_name')->get()->groupBy('seller_id')->map(function (Collection $rows) {
            $seller = $rows->first()->seller;

            return [
                'seller_id' => $seller->id, 'seller' => $seller->business_name ?: $seller->name, 'total' => $rows->count(),
                'complaints' => $rows->where('kind', 'complaint')->count(), 'returns' => $rows->where('kind', 'return')->count(), 'promos' => $rows->where('kind', 'promo')->count(),
                'serious' => $rows->whereIn('status', ['escalated', 'closed'])->count(), 'waiting' => $rows->whereIn('status', MarketCase::OPEN)->count(),
            ];
        })->sortByDesc('serious')->values();
    }

    // ------------------------------------------------------------------ helpers

    /** We get a copy of every complaint, so we can step in. */
    private function copyToUs(User $seller, string $what): void
    {
        foreach (User::withPermission('customers') as $reviewer) {
            Notice::create(['user_id' => $reviewer->id, 'kind' => 'market_case', 'body' => mb_substr(($seller->business_name ?: $seller->name).' '.$what, 0, 290)]);
        }
    }

    private function tellBuyer(MarketCase $case): void
    {
        if ($case->buyer) {
            $this->messages->send($case->buyer, 'market_case_answered', ['name' => $case->order->title], '/market/orders/'.$case->order->public_token);
        }
    }
}
