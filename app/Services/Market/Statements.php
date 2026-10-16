<?php

namespace App\Services\Market;

use App\Models\MarketStatement;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Carbon;

/**
 * The month-end commission statement (spec ch 14). At the start of a month each seller who made complete sales gets "Sales were X, our
 * commission is Y" and answers Paid or Problem. Paid is checked by us; a Problem needs the reason written; no answer for a set number
 * of days and selling is switched off until it is sorted out. Money never passes through us: the seller pays us directly.
 */
class Statements
{
    public function __construct(private MarketOrders $orders, private Messenger $messages) {}

    public function replyDays(): int
    {
        return max(1, (int) Setting::read('market.reply_days', 10));
    }

    /**
     * Send the statements for a month (the one before this, by default). A seller already sent one for that month is skipped.
     *
     * @return int how many were sent
     */
    public function generate(?string $month = null): int
    {
        $month ??= now()->subMonthNoOverflow()->format('Y-m');
        $sent = 0;

        foreach ($this->orders->owed($month) as $row) {
            if (MarketStatement::where('seller_id', $row['seller_id'])->where('month', $month)->exists()) {
                continue;
            }

            $statement = MarketStatement::create([
                'seller_id' => $row['seller_id'], 'month' => $month, 'orders' => $row['orders'], 'sales' => $row['sales'], 'commission' => $row['commission'], 'status' => 'sent', 'sent_at' => now(),
            ]);
            $this->messages->send(User::findOrFail($row['seller_id']), 'market_statement', ['name' => $month, 'sales' => $row['sales'], 'commission' => $row['commission']], '/wholesale/sell/statements');
            $sent++;

            unset($statement);
        }

        return $sent;
    }

    /** The seller answers: Paid, or Problem with the reason. */
    public function reply(MarketStatement $statement, User $seller, string $answer, ?string $note = null): MarketStatement
    {
        if ($statement->seller_id !== $seller->id) {
            throw new StockException('This is not your statement.');
        }

        if (! in_array($statement->status, ['sent', 'problem', 'suspended'], true)) {
            throw new StockException('This statement was already answered.');
        }

        if (! in_array($answer, ['paid', 'problem'], true)) {
            throw new StockException('Answer Paid or Problem.');
        }

        if ($answer === 'problem' && ($note === null || mb_strlen(trim($note)) < 3)) {
            throw new StockException('Write what the problem is.');
        }

        $statement->update(['status' => $answer, 'seller_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null, 'replied_at' => now()]);

        // An answer, even one we have yet to check, ends the silence: selling comes back and we look at what was said.
        $this->liftIfClear($seller);

        foreach (User::withPermission('customers') as $reviewer) {
            Notice::create(['user_id' => $reviewer->id, 'kind' => 'statement_reply', 'body' => mb_substr(($seller->business_name ?: $seller->name).': '.$answer.' ('.$statement->month.')', 0, 290)]);
        }

        return $statement->fresh();
    }

    /** We checked that it was paid, or settled the problem. If selling was switched off for this, it comes back. */
    public function verify(MarketStatement $statement, User $by, ?string $note = null): MarketStatement
    {
        if (! in_array($statement->status, ['paid', 'problem', 'suspended'], true)) {
            throw new StockException('There is nothing to check on this statement.');
        }

        $statement->update(['status' => 'verified', 'our_note' => $note ? mb_substr(trim($note), 0, 300) : null, 'decided_at' => now(), 'decided_by' => $by->id]);
        $this->liftIfClear($statement->seller);

        return $statement->fresh();
    }

    /** It was not paid, or the problem is not settled: it goes back to the seller to answer again. */
    public function reopen(MarketStatement $statement, User $by, string $note): MarketStatement
    {
        if (! in_array($statement->status, ['paid', 'problem'], true)) {
            throw new StockException('There is nothing to send back on this statement.');
        }

        $statement->update(['status' => 'sent', 'our_note' => mb_substr(trim($note), 0, 300), 'decided_at' => now(), 'decided_by' => $by->id, 'sent_at' => now()]);
        $this->messages->send($statement->seller, 'market_statement', ['name' => $statement->month, 'sales' => (float) $statement->sales, 'commission' => (float) $statement->commission], '/wholesale/sell/statements');

        return $statement->fresh();
    }

    /**
     * A statement nobody answered in time switches selling off for that seller: their listings stop showing, they take no new orders.
     * Orders already made carry on. Returns how many sellers were stopped.
     */
    public function suspendSilent(): int
    {
        $stopped = 0;

        MarketStatement::where('status', 'sent')->where('sent_at', '<=', now()->subDays($this->replyDays()))->with('seller')->each(function (MarketStatement $s) use (&$stopped) {
            $s->update(['status' => 'suspended', 'decided_at' => now()]);

            if ($s->seller && $s->seller->seller_suspended_at === null) {
                $s->seller->forceFill(['seller_suspended_at' => now()])->save();
                $this->messages->send($s->seller, 'market_suspended', ['name' => $s->month], '/wholesale/sell/statements');
                $stopped++;
            }
        });

        return $stopped;
    }

    /** Selling comes back once nothing is left waiting or unsettled. */
    public function liftIfClear(User $seller): void
    {
        $seller = $seller->fresh() ?? $seller;
        $open = MarketStatement::where('seller_id', $seller->id)->whereIn('status', ['sent', 'suspended'])->exists();

        if (! $open && $seller->seller_suspended_at !== null) {
            $seller->forceFill(['seller_suspended_at' => null])->save();
        }
    }

    /** Lift the stop by hand, for a seller we have decided to trust again. */
    public function lift(User $seller): void
    {
        $seller->forceFill(['seller_suspended_at' => null])->save();
    }

    public static function monthName(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)->format('F Y');
    }
}
