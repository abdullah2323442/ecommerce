<?php

namespace App\Services\Market;

use App\Models\DepositEntry;
use App\Models\Listing;
use App\Models\SellerDeposit;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The seller's security deposit (spec ch 14): money kept with us against returns and unpaid commission. The option exists but stays off until trust is built
 * (a setting). When it is on, a seller who holds less than is asked cannot send in new listings. Taking it in, giving it back and deducting from it are all
 * written down with who did it and why, and the seller is told.
 */
class SellerDeposits
{
    public function __construct(private Messenger $messages, private ActivityLogger $activity) {}

    public function enabled(): bool
    {
        return (bool) Setting::read('deposit.enabled', false);
    }

    public function usualAmount(): float
    {
        return max(0.0, round((float) Setting::read('deposit.amount', 0), 2));
    }

    /** What this seller is asked to keep with us: their own amount if the owner set one, otherwise the usual amount. Nothing when the option is off. */
    public function requiredFor(User $seller): float
    {
        if (! $this->enabled()) {
            return 0.0;
        }

        $own = SellerDeposit::where('seller_id', $seller->id)->value('required');

        return $own !== null ? round((float) $own, 2) : $this->usualAmount();
    }

    public function held(User $seller): float
    {
        return round((float) DepositEntry::where('seller_id', $seller->id)->sum('amount'), 2);
    }

    /** How much more is needed before this seller may send in new listings. */
    public function short(User $seller): float
    {
        return max(0.0, round($this->requiredFor($seller) - $this->held($seller), 2));
    }

    /** @throws StockException */
    public function guard(User $seller): void
    {
        if ($this->short($seller) > 0) {
            throw new StockException('You need to keep a deposit with us before you send in new products. Open your Sell page to see how much.');
        }
    }

    /** @return array{on: bool, required: float, held: float, short: float} for the seller's own page */
    public function status(User $seller): array
    {
        return ['on' => $this->enabled(), 'required' => $this->requiredFor($seller), 'held' => $this->held($seller), 'short' => $this->short($seller)];
    }

    /**
     * Every seller who has listed something or has money held, for the owner's list.
     *
     * @return Collection<int, array{id: int, name: string, required: float, own: ?float, held: float, short: float}>
     */
    public function sellers(): Collection
    {
        $ids = DepositEntry::query()->select('seller_id')->union(Listing::query()->select('seller_id'));

        return User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'business_name'])->map(function (User $u) {
            $own = SellerDeposit::where('seller_id', $u->id)->value('required');

            return [
                'id' => $u->id, 'name' => $u->business_name ?: $u->name, 'required' => $this->requiredFor($u), 'own' => $own === null ? null : (float) $own,
                'held' => $this->held($u), 'short' => $this->short($u),
            ];
        })->values();
    }

    /** @return list<array{id: int, amount: float, kind: string, reason: ?string, at: string, by: ?string}> */
    public function history(User $seller, int $limit = 20): array
    {
        return DepositEntry::where('seller_id', $seller->id)->with('doneBy:id,name')->latest('id')->limit($limit)->get()->map(fn (DepositEntry $e) => [
            'id' => $e->id, 'amount' => (float) $e->amount, 'kind' => $e->kind, 'reason' => $e->reason, 'at' => $e->created_at->toIso8601String(), 'by' => $e->doneBy?->name,
        ])->all();
    }

    /** The owner's own required amount for one seller; empty goes back to the usual amount. */
    public function setRequired(User $seller, ?float $amount, User $by): void
    {
        SellerDeposit::updateOrCreate(['seller_id' => $seller->id], ['required' => $amount !== null ? round(max(0, $amount), 2) : null]);
        $this->activity->record('deposit.required_set', $seller, ['required' => $amount], $by);
    }

    /** @throws StockException */
    public function receive(User $seller, float $amount, ?string $note, User $by): void
    {
        $this->entry($seller, $this->positive($amount), 'received', $note, $by);
    }

    /** Give money back. It cannot be more than is held. @throws StockException */
    public function giveBack(User $seller, float $amount, ?string $note, User $by): void
    {
        $this->taking($seller, $amount, 'returned', $note, $by);
    }

    /** Keep money for a reason, such as a return the seller did not refund. The reason is written down and the seller is told. @throws StockException */
    public function deduct(User $seller, float $amount, string $reason, User $by): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new StockException('Write the reason. The seller is told.');
        }

        $this->taking($seller, $amount, 'deducted', $reason, $by);
    }

    /** @throws StockException */
    private function taking(User $seller, float $amount, string $kind, ?string $note, User $by): void
    {
        $amount = $this->positive($amount);

        DB::transaction(function () use ($seller, $amount, $kind, $note, $by) {
            // Lock the seller's row so two people cannot take the same money twice.
            User::whereKey($seller->id)->lockForUpdate()->first();

            if ($amount > $this->held($seller) + 0.001) {
                throw new StockException('That is more than is held.');
            }

            $this->entry($seller, -$amount, $kind, $note, $by);
        });
    }

    /** @throws StockException */
    private function positive(float $amount): float
    {
        if ($amount <= 0) {
            throw new StockException('Write an amount above zero.');
        }

        return round($amount, 2);
    }

    private function entry(User $seller, float $signed, string $kind, ?string $note, User $by): void
    {
        $reason = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null;
        DepositEntry::create(['seller_id' => $seller->id, 'amount' => $signed, 'kind' => $kind, 'reason' => $reason, 'by' => $by->id]);
        $this->activity->record('deposit.'.$kind, $seller, ['amount' => abs($signed), 'reason' => $reason], $by);
        $this->messages->send($seller, 'market_deposit', ['name' => $kind, 'amount' => number_format(abs($signed), 2, '.', ','), 'note' => (string) $reason], '/wholesale/sell');
    }
}
