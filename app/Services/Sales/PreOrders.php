<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Preorder;
use App\Models\PreorderLine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use App\Services\Pricing\PriceRounding;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pre-order (spec ch 21). A sold-out product can be pre-ordered with a promised time of 2 to 5 days. The seller answers
 * "accepted" or "declined"; nothing is promised about stock. The pre-order is a draft memo that stays editable until the
 * goods arrive and the real quantities are written in, and then it becomes the real order. A pre-order past its time turns
 * red; one that never comes ends with a sorry note and the advance goes back.
 */
class PreOrders
{
    public const DAYS = [2, 3, 4, 5];

    public function __construct(private InvoiceService $invoices, private ActivityLogger $activity) {}

    // ------------------------------------------------------------------ switching it on

    /** The promised days for this medicine, or null when pre-order is off for it. A medicine's own setting beats its company's. */
    public function daysFor(Medicine $medicine): ?int
    {
        if ($medicine->preorder_days !== null) {
            return $medicine->preorder_days > 0 ? (int) $medicine->preorder_days : null;
        }

        $days = $medicine->company?->preorder_days;

        return $days ? (int) $days : null;
    }

    // ------------------------------------------------------------------ the buyer

    /** @throws StockException */
    public function request(User $customer, Medicine $medicine, string $unit, float $quantity): Preorder
    {
        if (! in_array($customer->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true) || $customer->isSuspended()) {
            throw new StockException('Only a customer account can pre-order.');
        }

        $days = $this->daysFor($medicine);
        if ($days === null) {
            throw new StockException('Pre-order is not open for this medicine.');
        }

        if (! in_array($unit, InvoiceService::UNITS, true) || ($unit !== 'piece' && $medicine->packaging_type !== 'box')) {
            throw new StockException('Pick how you want to buy it.');
        }

        if ($quantity <= 0 || $quantity > 100000) {
            throw new StockException('The quantity must be more than zero.');
        }

        $already = Preorder::where('customer_id', $customer->id)->whereIn('status', ['requested', 'accepted'])
            ->whereHas('lines', fn ($q) => $q->where('medicine_id', $medicine->id)->where('unit', $unit))->exists();
        if ($already) {
            throw new StockException('You already have a pre-order for this. Change it from your pre-orders.');
        }

        return DB::transaction(function () use ($customer, $medicine, $unit, $quantity, $days) {
            $pre = Preorder::create(['customer_id' => $customer->id, 'status' => 'requested', 'promised_days' => $days]);
            $pre->lines()->create(['medicine_id' => $medicine->id, 'unit' => $unit, 'quantity' => $quantity]);

            // An advance, when the owner asks for one: a share of what the buyer would pay today.
            $percent = (float) Setting::read('preorder.advance_percent', 0);
            if ($percent > 0) {
                $pre->update(['advance_amount' => PriceRounding::round($this->expectedTotal($pre->fresh('lines.medicine', 'customer')) * $percent / 100), 'advance_state' => 'promised']);
            }

            return $pre->fresh();
        });
    }

    /** The buyer changes their mind. A pre-order past its time gives the advance back in full; before that the seller decides. */
    public const NOT_TAKEN_REFUND_PERCENT = 50;

    public function cancelByBuyer(Preorder $pre): Preorder
    {
        if (! $pre->isOpen()) {
            throw new StockException('This pre-order is already closed.');
        }

        $late = $pre->isLate();
        $pre->update(['status' => 'cancelled', 'cancelled_by' => 'buyer', 'advance_state' => $late && $pre->advance_state === 'held' ? 'refund_due' : $pre->advance_state]);

        return $pre->fresh();
    }

    // ------------------------------------------------------------------ the seller

    public function accept(Preorder $pre, ?int $days, User $by): Preorder
    {
        $this->mustBe($pre, ['requested']);
        $days ??= (int) $pre->promised_days;

        if (! in_array($days, self::DAYS, true)) {
            throw new StockException('Pick 2, 3, 4 or 5 days.');
        }

        $pre->update(['status' => 'accepted', 'promised_days' => $days, 'due_on' => today()->addDays($days), 'accepted_at' => now()]);
        $this->tell($pre, 'preorder_accepted', ['name' => $this->name($pre), 'days' => $days]);
        $this->activity->record('preorder.accepted', $pre, ['days' => $days], $by);

        return $pre->fresh();
    }

    public function decline(Preorder $pre, string $note, User $by): Preorder
    {
        $this->mustBe($pre, ['requested']);

        $pre->update(['status' => 'declined', 'reply_note' => $note, 'cancelled_by' => 'seller']);
        $this->tell($pre, 'preorder_declined', ['name' => $this->name($pre), 'note' => $note]);
        $this->activity->record('preorder.declined', $pre, ['note' => $note], $by);

        return $pre->fresh();
    }

    /** The goods never came: the pre-order ends with a sorry note, the advance goes back, and nothing hangs. */
    public function cancelBySeller(Preorder $pre, string $note, ?User $by): Preorder
    {
        $this->mustBe($pre, ['requested', 'accepted']);

        $pre->update([
            'status' => 'cancelled', 'cancelled_by' => 'seller', 'cancel_note' => $note,
            'advance_state' => in_array($pre->advance_state, ['held', 'promised'], true) ? ($pre->advance_state === 'held' ? 'refund_due' : 'none') : $pre->advance_state,
        ]);
        $this->tell($pre, 'preorder_cancelled', ['name' => $this->name($pre), 'note' => $note]);
        $this->activity->record('preorder.cancelled', $pre, ['note' => $note], $by);

        return $pre->fresh();
    }

    /** A new time when the old one has passed or is about to. */
    public function extend(Preorder $pre, int $days, User $by): Preorder
    {
        $this->mustBe($pre, ['accepted']);

        if (! in_array($days, self::DAYS, true)) {
            throw new StockException('Pick 2, 3, 4 or 5 days.');
        }

        $pre->update(['due_on' => today()->addDays($days), 'promised_days' => $days, 'extended' => $pre->extended + 1]);
        $this->tell($pre, 'preorder_extended', ['name' => $this->name($pre), 'days' => $days]);
        $this->activity->record('preorder.extended', $pre, ['days' => $days], $by);

        return $pre->fresh();
    }

    /** What happened to the advance: received, refunded or kept. */
    public function setAdvance(Preorder $pre, string $state, User $by): Preorder
    {
        $allowed = ['held' => ['promised', 'none'], 'refunded' => ['held', 'refund_due'], 'half' => ['held', 'refund_due'], 'kept' => ['held', 'refund_due']];

        if (! isset($allowed[$state]) || ! in_array($pre->advance_state, $allowed[$state], true)) {
            throw new StockException('That cannot be done with this advance now.');
        }

        // Goods not taken: half of the advance goes back, half stays for the time and effort spent (spec ch 12).
        $back = match ($state) {
            'refunded' => (float) $pre->advance_amount,
            'half' => round((float) $pre->advance_amount * self::NOT_TAKEN_REFUND_PERCENT / 100, 2),
            default => 0.0,
        };
        $pre->update(['advance_state' => $state === 'half' ? 'refunded' : $state, 'advance_refunded' => $back]);
        $this->activity->record('preorder.advance', $pre, ['state' => $state, 'amount' => (float) $pre->advance_amount], $by);

        return $pre->fresh();
    }

    /** The draft memo stays editable while it is open: change how much is wanted. */
    public function editLine(PreorderLine $line, float $quantity, User $by): PreorderLine
    {
        $pre = $line->preorder;
        $this->mustBe($pre, ['requested', 'accepted']);

        if ($quantity <= 0 || $quantity > 100000) {
            throw new StockException('The quantity must be more than zero.');
        }

        $before = (float) $line->quantity;
        $line->update(['quantity' => $quantity]);
        $this->activity->record('preorder.line_edited', $pre, ['before' => $before, 'after' => $quantity], $by);

        return $line->fresh();
    }

    /**
     * The goods arrived: write in how much of each came. The draft memo becomes the real order for what came, which takes
     * the stock now, and the buyer is told for the first time. Whatever did not come is written on the pre-order.
     *
     * @param  array<int, float|int|string>  $received  line id => quantity that arrived
     *
     * @throws StockException when there is not enough stock yet, so the goods have to be received into stock first
     */
    public function fulfil(Preorder $pre, array $received, User $by): Invoice
    {
        $this->mustBe($pre, ['accepted']);
        $pre->load('lines.medicine', 'customer');

        $amounts = [];
        foreach ($pre->lines as $line) {
            $got = (float) ($received[$line->id] ?? 0);
            if ($got < 0 || $got > (float) $line->quantity) {
                throw new StockException('You cannot receive more than was ordered.');
            }
            $amounts[$line->id] = $got;
        }

        if (array_sum($amounts) <= 0) {
            throw new StockException('Write how much arrived.');
        }

        return DB::transaction(function () use ($pre, $amounts, $by) {
            $customer = $pre->customer;
            $kind = match ($customer->account_type) {
                AccountType::Paikari => 'paikari',
                AccountType::Wholesaler => 'wholesale',
                default => 'retail',
            };

            $order = $this->invoices->open($kind, $by, $customer, 'online', $customer->name);
            foreach ($pre->lines as $line) {
                if ($amounts[$line->id] > 0) {
                    $added = $this->invoices->addLine($order, $line->medicine, $line->unit, $amounts[$line->id], $by);

                    if ($pre->box_price !== null) {
                        $added->update(['unit_price' => $pre->box_price, 'rate_layer' => 'mpo', 'percent_off' => 0]);

                        // The bonus (10+2) comes as its own line with no price, in proportion to what came (spec ch 10).
                        $free = (int) floor((int) $pre->bonus_free * $amounts[$line->id] / max(1.0, (float) $line->quantity));
                        if ($free > 0) {
                            $bonus = $this->invoices->addLine($order, $line->medicine, $line->unit, (float) $free, $by, null, false, true);
                            $bonus->update(['unit_price' => 0, 'rate_layer' => 'bonus', 'percent_off' => 100]);
                        }
                    }
                }
            }

            $this->invoices->place($order);
            foreach ($order->lines()->get() as $orderLine) {
                $this->invoices->verifyLine($orderLine, 'full', null, $by);
            }

            $note = 'Pre-order #'.$pre->id.($pre->advance_state === 'held' ? ' (advance '.number_format((float) $pre->advance_amount, 2, '.', '').' received)' : '');
            $update = ['note' => $note];
            if ($kind === 'retail') {
                $update += ['public_token' => Str::random(40), 'delivery_mode' => 'collect', 'ship_to' => ['name' => $customer->name, 'phone' => $customer->phone]];
            }
            $order->update($update);
            $this->invoices->completeVerification($order->fresh(), $by);

            foreach ($pre->lines as $line) {
                $line->update(['received_quantity' => $amounts[$line->id]]);
            }

            $short = $pre->lines->contains(fn ($l) => $amounts[$l->id] < (float) $l->quantity);
            $pre->update(['status' => 'fulfilled', 'invoice_id' => $order->id, 'told_at' => now(), 'advance_state' => $pre->advance_state === 'held' ? 'applied' : $pre->advance_state]);
            $this->tell($pre, 'preorder_arrived', ['name' => $this->name($pre), 'short' => $short, 'token' => $order->fresh()->public_token]);
            $this->activity->record('preorder.fulfilled', $pre, ['invoice' => $order->id, 'short' => $short], $by);

            return $order->fresh();
        });
    }

    /**
     * The goods from an MPO reached the owner's hands: they enter stock with the source "Through MPO" (spec ch 10), at what was
     * paid, so profit stays right. After this the pre-order can be received like any other.
     *
     * @throws StockException
     */
    public function takeFromMpo(Preorder $pre, float $percentPaid, User $by): void
    {
        $this->mustBe($pre, ['accepted']);

        if ($pre->mpo_listing_id === null) {
            throw new StockException('This is not an order from an MPO.');
        }

        if ($percentPaid < 0 || $percentPaid >= 100) {
            throw new StockException('The discount you paid is between 0 and 100 percent.');
        }

        $line = $pre->lines()->firstOrFail();
        $source = StockSource::firstOrCreate(['name' => 'Through MPO'], ['type' => 'mpo', 'default_percent' => 0, 'is_active' => true]);

        app(StockService::class)->receive($source, [
            'invoice_date' => today()->toDateString(), 'note' => 'Pre-order #'.$pre->id,
            'lines' => [['medicine_id' => $line->medicine_id, 'units' => (float) $line->quantity, 'bonus' => (int) $pre->bonus_free, 'company_percent' => $percentPaid]],
        ], $by);

        $this->activity->record('preorder.taken_from_mpo', $pre, ['percent' => $percentPaid], $by);
    }

    // ------------------------------------------------------------------ reading

    public function expectedTotal(Preorder $pre): float
    {
        $pre->loadMissing('lines.medicine', 'customer');
        $probe = (new Invoice(['kind' => $this->kindFor($pre->customer), 'channel' => 'online']))->setRelation('customer', $pre->customer);

        return round((float) $pre->lines->sum(fn (PreorderLine $l) => $this->priceOf($pre, $l, $probe) * (float) $l->quantity), 2);
    }

    public function unitPrice(Preorder $pre, PreorderLine $line): float
    {
        $pre->loadMissing('customer');
        $probe = (new Invoice(['kind' => $this->kindFor($pre->customer), 'channel' => 'online']))->setRelation('customer', $pre->customer);

        return $this->priceOf($pre, $line, $probe);
    }

    /** An order from an MPO listing has the price agreed for its market; any other pre-order is at the buyer's own rate. */
    private function priceOf(Preorder $pre, PreorderLine $line, Invoice $probe): float
    {
        return $pre->box_price !== null ? (float) $pre->box_price : $this->invoices->rate($probe, $line->medicine, $line->unit)['price'];
    }

    /** Waiting for an answer, plus accepted ones past their time: the number the seller sees on the menu. */
    public function attention(): int
    {
        return Preorder::where('status', 'requested')->count() + $this->late()->count();
    }

    public function late(): Collection
    {
        return Preorder::where('status', 'accepted')->whereDate('due_on', '<', today())->get();
    }

    /**
     * The buying list (spec ch 21): everything shops have pre-ordered, grouped by company and product, so it is in front of the
     * owner when ordering from the company. It is not linked to stock; it is only here so nothing is forgotten.
     *
     * @return Collection<int, array{company: string, medicines: list<array{name: string, entries: list<array{id: int, who: string, quantity: float, unit: string}>}>}>
     */
    public function buyingList(): Collection
    {
        $lines = PreorderLine::query()->whereHas('preorder', fn ($q) => $q->whereIn('status', ['requested', 'accepted']))
            ->with(['preorder:id,customer_id,created_at', 'preorder.customer:id,name', 'medicine:id,brand_name,strength,company_id', 'medicine.company:id,name'])->get()
            ->sortBy(fn (PreorderLine $l) => $l->preorder_id);

        return $lines->groupBy(fn (PreorderLine $l) => $l->medicine->company?->name ?? '—')->sortKeys()->map(fn (Collection $byCompany, string $company) => [
            'company' => $company,
            'medicines' => $byCompany->groupBy('medicine_id')->map(fn (Collection $rows) => [
                'medicine_id' => (int) $rows->first()->medicine_id, 'name' => trim($rows->first()->medicine->brand_name.' '.$rows->first()->medicine->strength),
                // First come, first served: the list is in the order the pre-orders were made.
                'entries' => $rows->map(fn (PreorderLine $l) => ['id' => $l->preorder_id, 'who' => $l->preorder->customer?->name ?? '—', 'quantity' => (float) $l->quantity, 'unit' => $l->unit])->values()->all(),
            ])->values()->all(),
        ])->values();
    }

    /**
     * "10 people wanted it, 6 came" (spec ch 21): share what arrived out in the order it was asked for. The first pre-orders get all they asked
     * for, the one where it runs out gets what is left, the rest get nothing this time and are told. What each is given is written on its line
     * as the amount to receive; nothing is received until the staff press Receive.
     *
     * @return array<int, array{line_id: int, preorder_id: int, who: string, asked: float, give: float, unit: string}>
     *
     * @throws StockException
     */
    public function share(Medicine $medicine, float $boxes, User $by): array
    {
        if ($boxes <= 0) {
            throw new StockException('Write how many boxes arrived.');
        }

        $lines = PreorderLine::where('medicine_id', $medicine->id)->whereNull('received_quantity')
            ->whereHas('preorder', fn ($q) => $q->where('status', 'accepted'))->with('preorder.customer')->orderBy('preorder_id')->orderBy('id')->get();

        $left = $boxes;
        $plan = [];

        foreach ($lines as $line) {
            $per = InvoiceService::fraction($medicine, $line->unit);
            $asked = (float) $line->quantity;
            $give = min($asked, (float) floor($left / $per + 1e-9));
            $left = max(0.0, $left - $give * $per);
            $line->update(['share_quantity' => $give]);

            if ($give < $asked) {
                $this->tell($line->preorder, 'preorder_short', ['name' => trim($medicine->brand_name.' '.$medicine->strength), 'given' => $give, 'asked' => $asked]);
            }

            $plan[] = ['line_id' => $line->id, 'preorder_id' => $line->preorder_id, 'who' => $line->preorder->customer?->name ?? '—', 'asked' => $asked, 'give' => $give, 'unit' => $line->unit];
        }

        $this->activity->record('preorder.shared', $medicine, ['boxes' => $boxes, 'lines' => count($plan)], $by);

        return $plan;
    }

    // ------------------------------------------------------------------ helpers

    private function kindFor(User $customer): string
    {
        return match ($customer->account_type) {
            AccountType::Paikari => 'paikari',
            AccountType::Wholesaler => 'wholesale',
            default => 'retail',
        };
    }

    private function name(Preorder $pre): string
    {
        $line = $pre->lines()->with('medicine')->first();

        return $line ? trim($line->medicine->brand_name.' '.$line->medicine->strength) : '';
    }

    /** @param  list<string>  $statuses */
    private function mustBe(Preorder $pre, array $statuses): void
    {
        if (! in_array($pre->status, $statuses, true)) {
            throw new StockException('This pre-order is already closed.');
        }
    }

    private function tell(Preorder $pre, string $kind, array $body): void
    {
        app(Messenger::class)->send($pre->customer, $kind, $body + ['id' => $pre->id], '/preorders/'.$pre->id);
    }
}
