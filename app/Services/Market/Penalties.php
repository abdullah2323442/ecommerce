<?php

namespace App\Services\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\SellerPenalty;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;

/**
 * Penalty steps for a seller who breaks the rules (spec ch 14): warning, then the listing removed, then suspended, then payouts withheld.
 * The owner chooses; the system only says what the next step would be and when the record looks bad enough to think about one.
 * Online payouts do not exist yet, so "withheld" is recorded and shown but holds nothing until they do.
 */
class Penalties
{
    public function __construct(private Messenger $messages) {}

    /** A seller with this many "not received" in 90 days should be looked at (spec ch 14: repeated means suspended). */
    public function missingLimit(): int
    {
        return max(1, (int) Setting::read('market.missing_limit', 3));
    }

    /** @throws StockException */
    public function issue(User $seller, User $by, string $step, string $reason, ?Listing $listing = null): SellerPenalty
    {
        if (! in_array($step, SellerPenalty::STEPS, true)) {
            throw new StockException('Choose a step.');
        }

        if (mb_strlen(trim($reason)) < 3) {
            throw new StockException('Write the reason. The seller is told.');
        }

        if ($step === 'listing_removed' && ($listing === null || $listing->seller_id !== $seller->id)) {
            throw new StockException('Pick one of this seller\'s listings to remove.');
        }

        if ($this->active($seller)->contains('step', $step) && in_array($step, ['suspended', 'withheld'], true)) {
            throw new StockException('This step is already on.');
        }

        $reason = mb_substr(trim($reason), 0, 300);

        match ($step) {
            'listing_removed' => $listing->update(['status' => 'rejected', 'review_note' => $reason]),
            'suspended' => $seller->forceFill(['penalty_suspended_at' => now()])->save(),
            'withheld' => $seller->forceFill(['payout_withheld_at' => now()])->save(),
            default => null,
        };

        $penalty = SellerPenalty::create(['seller_id' => $seller->id, 'step' => $step, 'reason' => $reason, 'listing_id' => $listing?->id, 'issued_by' => $by->id]);
        // Warnings and removals are over once said and done; a suspension or a hold stays until it is lifted.
        if (in_array($step, ['warning', 'listing_removed'], true)) {
            $penalty->update(['lifted_at' => now()]);
        }

        $this->messages->send($seller, 'market_penalty', ['name' => $step, 'note' => $reason], '/wholesale/sell/statements');

        return $penalty;
    }

    /** Switch the suspension and the hold off together: the seller is back in good standing. */
    public function lift(User $seller): void
    {
        $seller->forceFill(['penalty_suspended_at' => null, 'payout_withheld_at' => null])->save();
        SellerPenalty::where('seller_id', $seller->id)->whereNull('lifted_at')->update(['lifted_at' => now()]);
    }

    /** What is on now (suspension, hold). */
    public function active(User $seller): Collection
    {
        return SellerPenalty::where('seller_id', $seller->id)->whereNull('lifted_at')->latest('id')->get();
    }

    public function history(User $seller): Collection
    {
        return SellerPenalty::where('seller_id', $seller->id)->with('listing:id,title')->latest('id')->limit(20)->get();
    }

    /** The step after the worst one taken so far. */
    public function next(User $seller): string
    {
        $done = SellerPenalty::where('seller_id', $seller->id)->pluck('step')->all();

        foreach (SellerPenalty::STEPS as $step) {
            if (! in_array($step, $done, true)) {
                return $step;
            }
        }

        return 'withheld';
    }

    /** Orders in the last 90 days the buyer said never arrived. */
    public function missing(User $seller): int
    {
        return MarketOrder::where('seller_id', $seller->id)->where('status', 'not_received')->where('not_received_at', '>=', now()->subDays(90))->count();
    }

    /** Sellers whose record says a step should be thought about: too many "not received", or serious cases against them. */
    public function watch(): Collection
    {
        return User::where('account_type', AccountType::Wholesaler)->get(['id', 'name', 'business_name'])
            ->map(fn (User $s) => ['seller_id' => $s->id, 'seller' => $s->business_name ?: $s->name, 'missing' => $this->missing($s), 'next' => $this->next($s)])
            ->filter(fn ($r) => $r['missing'] >= $this->missingLimit())->values();
    }
}
