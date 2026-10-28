<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\OfferProduct;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Payments\Payments;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\PriceRounding;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use App\Services\Sync\PharmaTrack;
use App\Services\Wallet\Tokens;
use Illuminate\Support\Facades\DB;

/**
 * Invoices for the counter and for online orders (spec 4.3.5, 4.4.2, 4.5).
 *
 * A draft is the open_invoice. Each line is saved the moment it is added (DRF-9) and, when its kind of sale
 * deducts stock, takes that stock then and there; removing the line puts back exactly what it took.
 *
 * Rate priority at the counter: the % typed for this line, then the % typed at the top of the invoice, then
 * the customer's own rate from the pricing engine. Retail has no customer: MRP, then one discount at the
 * bottom that never touches a line that already has its own special discount.
 */
class InvoiceService
{
    public const UNITS = ['piece', 'strip', 'box'];

    public const PAYMENT_METHODS = ['cash', 'bkash', 'nagad', 'rocket', 'bank', 'card', 'due'];

    public function __construct(private PriceResolver $prices, private StockService $stock, private OfferProducts $offers, private ReturnService $returns, private ControlledRegister $controlled, private Messenger $messages, private QuantitySteps $steps) {}

    // ------------------------------------------------------------------ units and rates

    /** How much of one stock unit a chosen unit is. A box is 1; a strip is 1 / strips-per-box; loose items are always 1. */
    public static function fraction(Medicine $m, string $unit): float
    {
        if ($m->packaging_type !== 'box') {
            return 1.0;
        }

        $strips = max(1, (int) $m->strips_per_box);
        $pieces = max(1, (int) $m->pieces_per_strip);

        return match ($unit) {
            'box' => 1.0,
            'strip' => 1 / $strips,
            default => 1 / ($strips * $pieces),
        };
    }

    /** MRP of one chosen unit, from the printed MRPs where we have them. */
    public static function unitMrp(Medicine $m, string $unit): float
    {
        $printed = match ($unit) {
            'box' => $m->mrp_box,
            'strip' => $m->mrp_strip,
            default => $m->mrp_piece,
        };

        if ($printed !== null && (float) $printed > 0) {
            return (float) $printed;
        }

        return round(StockService::unitMrp($m) * self::fraction($m, $unit), 2);
    }

    /**
     * What one unit costs the customer on this invoice, and why.
     *
     * @return array{mrp: float, price: float, percent: float, layer: string}
     */
    public function rate(Invoice $invoice, Medicine $medicine, string $unit, ?float $special = null, ?float $quantity = null): array
    {
        $mrp = self::unitMrp($medicine, $unit);

        // Public price: buying more earns a step discount (spec ch 4). A price someone set by hand is left alone.
        if ($invoice->kind === 'retail' && $special === null && $quantity !== null && ($step = $this->steps->stepFor($medicine, $unit, $quantity))) {
            $price = $this->steps->apply($mrp, $step);

            return ['mrp' => $mrp, 'price' => $price, 'percent' => $mrp > 0 ? round((1 - $price / $mrp) * 100, 3) : 0.0, 'layer' => 'quantity'];
        }

        [$percent, $layer] = match (true) {
            $special !== null => [$special, 'special'],
            $invoice->kind === 'retail' => [0.0, 'mrp'],
            $invoice->invoice_percent !== null => [(float) $invoice->invoice_percent, 'invoice'],
            $invoice->customer !== null => $this->customerRate($invoice->customer, $medicine),
            default => [0.0, 'mrp'],
        };

        return ['mrp' => $mrp, 'price' => PriceRounding::round($mrp * (1 - $percent / 100)), 'percent' => $percent, 'layer' => $layer];
    }

    /** @return array{0: float, 1: string} */
    private function customerRate(User $customer, Medicine $medicine): array
    {
        $result = $this->prices->resolve($customer, $medicine);

        return [$result->percent, $result->layer];
    }

    // ------------------------------------------------------------------ drafts

    /** Open a draft. Retail drafts without a name become "Customer 1, 2, 3…" for the day (DRF-1). */
    public function open(string $kind, ?User $by, ?User $customer = null, string $channel = 'counter', ?string $label = null): Invoice
    {
        if (! in_array($kind, Invoice::KINDS, true)) {
            throw new StockException('Unknown kind of sale.');
        }

        if ($kind !== 'retail' && ! $customer) {
            throw new StockException('Pick the customer first.');
        }

        if ($kind === 'retail' && $label === null) {
            $label = 'Customer '.(Invoice::where('kind', 'retail')->whereDate('opened_at', today())->count() + 1);
        }

        return Invoice::create([
            'kind' => $kind, 'channel' => $channel, 'status' => 'draft', 'customer_id' => $customer?->id, 'label' => $label,
            'opened_by' => $by?->id, 'opened_at' => now(), 'last_activity_at' => now(),
        ]);
    }

    /**
     * Add a medicine. The same medicine in the same unit is merged into its line (DRF-8) unless `$separate`.
     * A line "from outside" is priced but takes no stock (DRF-10).
     *
     * @throws StockException
     */
    public function addLine(Invoice $invoice, Medicine $medicine, string $unit, float $quantity, ?User $by, ?float $special = null, bool $outside = false, bool $separate = false): InvoiceLine
    {
        $this->guard($invoice, $unit, $quantity);
        $this->checkDiscountLimit($by, $special);

        if (! $separate && ! $outside) {
            $existing = $invoice->lines()->where('medicine_id', $medicine->id)->where('unit', $unit)->where('is_outside', false)->first();
            if ($existing) {
                return $this->setQuantity($existing, (float) $existing->quantity + $quantity, $by);
            }
        }

        return DB::transaction(function () use ($invoice, $medicine, $unit, $quantity, $by, $special, $outside) {
            $rate = $this->rate($invoice, $medicine, $unit, $special, $quantity);
            $stockUnits = round($quantity * self::fraction($medicine, $unit), 4);

            $line = InvoiceLine::create([
                'invoice_id' => $invoice->id, 'medicine_id' => $medicine->id, 'unit' => $unit, 'quantity' => $quantity, 'stock_units' => $stockUnits,
                'unit_mrp' => $rate['mrp'], 'unit_price' => $rate['price'], 'rate_layer' => $rate['layer'], 'percent_off' => $rate['percent'],
                'special_percent' => $special, 'is_outside' => $outside,
            ]);

            $this->take($invoice, $line, $medicine, $by);
            $this->touch($invoice);

            return $line->fresh();
        });
    }

    /**
     * Add boxes from an offer product (spec 9.1). The price is the offer rate, never worse than the buyer's own rate,
     * with the extra % for boxes above the minimum. Below the minimum it is an ordinary line at the normal rate.
     * An order can never be more than the boxes that are on offer; the message does not say how many there are.
     *
     * @throws StockException
     */
    public function addOfferLine(Invoice $invoice, OfferProduct $offer, int $boxes, User $by): InvoiceLine
    {
        $this->guard($invoice, 'box', $boxes);
        $medicine = $offer->medicine;

        if ($boxes < (int) $offer->min_boxes) {
            return $this->addLine($invoice, $medicine, 'box', $boxes, $by);
        }

        if ($this->offers->state($offer) !== 'live') {
            throw new StockException('This offer is no longer on.');
        }

        $existing = $invoice->lines()->where('offer_product_id', $offer->id)->first();
        $total = $boxes + ($existing ? (int) $existing->quantity : 0);

        if ($total > $this->offers->available($offer)) {
            throw new StockException('That is more than this offer has.');
        }

        if ($existing) {
            return $this->setQuantity($existing, (float) $total, $by);
        }

        return DB::transaction(function () use ($invoice, $offer, $medicine, $boxes, $by) {
            $price = $this->offerPrice($invoice, $offer, $boxes);
            $mrp = (float) $medicine->mrp_box;

            $line = InvoiceLine::create([
                'invoice_id' => $invoice->id, 'medicine_id' => $medicine->id, 'offer_product_id' => $offer->id, 'unit' => 'box', 'quantity' => $boxes,
                'stock_units' => $boxes, 'unit_mrp' => $mrp, 'unit_price' => $price, 'rate_layer' => 'offer_product',
                'percent_off' => $mrp > 0 ? round((1 - $price / $mrp) * 100, 3) : 0,
            ]);

            $this->take($invoice, $line, $medicine, $by);
            $this->touch($invoice);

            return $line->fresh();
        });
    }

    /** An offer line follows its quantity: a bigger order earns the extra %, one under the minimum falls back to the normal rate. */
    private function repriceOfferLine(InvoiceLine $line, Invoice $invoice, int $boxes): void
    {
        if (! $line->offer_product_id) {
            return;
        }

        $offer = $line->offer;

        if (! $offer || $boxes < (int) $offer->min_boxes) {
            $rate = $this->rate($invoice, $line->medicine, 'box', $line->special_percent === null ? null : (float) $line->special_percent);
            $line->update(['offer_product_id' => null, 'unit_price' => $rate['price'], 'rate_layer' => $rate['layer'], 'percent_off' => $rate['percent']]);

            return;
        }

        $price = $this->offerPrice($invoice, $offer, $boxes);
        $mrp = (float) $line->unit_mrp;
        $line->update(['unit_price' => $price, 'percent_off' => $mrp > 0 ? round((1 - $price / $mrp) * 100, 3) : 0]);
    }

    /** A public line follows its quantity too: the step it reaches sets its price, and dropping below every step puts the printed price back. */
    private function repriceStepLine(InvoiceLine $line, Invoice $invoice, float $quantity): void
    {
        if ($invoice->kind !== 'retail' || $line->offer_product_id || $line->special_percent !== null || $line->is_outside || in_array($line->rate_layer, ['manual', 'bonus'], true)) {
            return;
        }

        $rate = $this->rate($invoice, $line->medicine, $line->unit, null, $quantity);
        $line->update(['unit_price' => $rate['price'], 'rate_layer' => $rate['layer'], 'percent_off' => $rate['percent']]);
    }

    /** What the viewer sees of an offer product: price per box at the minimum, and whether it can be ordered. Stock only if the admin chose to show it. */
    public function offerCard(Invoice $probe, OfferProduct $offer): array
    {
        $state = $this->offers->state($offer);
        $m = $offer->medicine;

        return [
            'id' => $offer->id, 'medicine_id' => $m->id, 'name' => $m->brand_name, 'strength' => $m->strength, 'company' => $m->company?->name,
            'min_boxes' => (int) $offer->min_boxes, 'step_percent' => (float) $offer->step_percent, 'mrp' => (float) $m->mrp_box,
            'price' => $this->offerPrice($probe, $offer, (int) $offer->min_boxes), 'state' => $state,
            'stock' => $offer->stock_mode === 'exact' && $state === 'live' ? (int) $this->offers->available($offer) : null,
            'weight_kg' => $offer->weight_kg !== null ? (float) $offer->weight_kg : null, 'delivery_charge' => $offer->delivery_charge !== null ? (float) $offer->delivery_charge : null,
        ];
    }

    /** The offer price for this buyer: the offer, unless their own rate is already better. */
    private function offerPrice(Invoice $invoice, OfferProduct $offer, int $boxes): float
    {
        $own = $invoice->customer ? $this->rate($invoice, $offer->medicine, 'box')['price'] : (float) $offer->medicine->mrp_box;

        return min($this->offers->boxPrice($offer, $boxes, $invoice->kind === 'retail' ? 'public' : $invoice->kind), $own);
    }

    /** Change a line's quantity: put back what it took, then take the new amount. */
    public function setQuantity(InvoiceLine $line, float $quantity, ?User $by): InvoiceLine
    {
        $invoice = $line->invoice;
        $this->guard($invoice, $line->unit, $quantity);

        return DB::transaction(function () use ($line, $quantity, $by, $invoice) {
            $medicine = $line->medicine;
            $this->stock->restore($line->id, $by);

            $line->update(['quantity' => $quantity, 'stock_units' => round($quantity * self::fraction($medicine, $line->unit), 4)]);
            $this->repriceOfferLine($line, $invoice, (int) round($quantity));
            $this->repriceStepLine($line->fresh(), $invoice, $quantity);
            $this->take($invoice, $line->fresh(), $medicine, $by);
            $this->touch($invoice);

            return $line->fresh();
        });
    }

    /**
     * The buyer changes the amount on a placed order, before staff start picking it (spec ch 13). Nothing is held in stock for an order nobody has checked, so only
     * the amount and the price that follows it (a step price, an offer minimum) change.
     *
     * @throws StockException
     */
    public function editPlacedLine(InvoiceLine $line, float $quantity): InvoiceLine
    {
        $order = $line->invoice;

        if ($order->channel !== 'online' || $order->status !== 'placed' || $this->pickingStarted($order)) {
            throw new StockException('It is too late to change this order. Please call us.');
        }

        if ($quantity <= 0 || $quantity > 100000 || floor($quantity) != $quantity) {
            throw new StockException('Write a whole number, more than zero.');
        }

        $line->update(['quantity' => $quantity, 'requested_quantity' => $quantity, 'stock_units' => round($quantity * self::fraction($line->medicine, $line->unit), 4)]);
        $this->repriceOfferLine($line->fresh(), $order, (int) $quantity);
        $this->repriceStepLine($line->fresh(), $order, $quantity);
        $this->touch($order);

        return $line->fresh();
    }

    /** The buyer takes one item off a placed order. The last one cannot go: that is cancelling. @throws StockException */
    public function dropPlacedLine(InvoiceLine $line): void
    {
        $order = $line->invoice;

        if ($order->channel !== 'online' || $order->status !== 'placed' || $this->pickingStarted($order)) {
            throw new StockException('It is too late to change this order. Please call us.');
        }

        if ($order->lines()->count() <= 1) {
            throw new StockException('This is the only item. Cancel the order instead.');
        }

        $line->update(['removed_at' => now()]);
        $this->touch($order);
    }

    /** Remove a line: its stock comes back at once and the line stays in the history. */
    public function removeLine(InvoiceLine $line, ?User $by): void
    {
        $this->guard($line->invoice, $line->unit, 1);

        DB::transaction(function () use ($line, $by) {
            $this->stock->restore($line->id, $by);
            $line->update(['removed_at' => now()]);
            $this->touch($line->invoice);
        });
    }

    /** DRF-2: fold another draft into this one. Lines keep a note of the draft they came from. Same customer kind only. */
    public function merge(Invoice $target, Invoice $source): Invoice
    {
        if ($target->id === $source->id || ! $target->isDraft() || ! $source->isDraft()) {
            throw new StockException('Only two open drafts can be merged.');
        }

        if ($target->kind !== $source->kind || $target->customer_id !== $source->customer_id) {
            throw new StockException('Drafts for different customers or kinds of sale cannot be merged.');
        }

        return DB::transaction(function () use ($target, $source) {
            // Stock was taken line by line, so the lines (and the stock links on them) simply move over.
            InvoiceLine::where('invoice_id', $source->id)->update(['invoice_id' => $target->id, 'from_invoice_id' => $source->id]);
            $source->update(['status' => 'cancelled', 'cancelled_at' => now(), 'note' => trim(($source->note ?? '').' Merged into '.($target->label ?? '#'.$target->id))]);
            $this->touch($target);

            return $target->fresh();
        });
    }

    /** DRF-7: move a line to another draft. The rate becomes the new customer's; stock does not move. */
    public function moveLine(InvoiceLine $line, Invoice $to): InvoiceLine
    {
        if (! $line->invoice->isDraft() || ! $to->isDraft()) {
            throw new StockException('Only a line on an open draft can be moved.');
        }

        $rate = $this->rate($to, $line->medicine, $line->unit, $line->special_percent === null ? null : (float) $line->special_percent, (float) $line->quantity);
        $line->update(['invoice_id' => $to->id, 'from_invoice_id' => $line->invoice_id, 'unit_mrp' => $rate['mrp'], 'unit_price' => $rate['price'], 'rate_layer' => $rate['layer'], 'percent_off' => $rate['percent']]);
        $this->touch($to);

        return $line->fresh();
    }

    // ------------------------------------------------------------------ totals

    /**
     * @return array{subtotal: float, discount: float, delivery: float, total: float, eligible: float}
     */
    public function totals(Invoice $invoice): array
    {
        $lines = $invoice->lines()->get();
        $subtotal = round((float) $lines->sum(fn (InvoiceLine $l) => $l->lineTotal()), 2);

        // The end discount is never added on top of a line that already carries its own special discount.
        $eligible = round((float) $lines->filter(fn (InvoiceLine $l) => $l->special_percent === null)->sum(fn (InvoiceLine $l) => $l->lineTotal()), 2);
        $discount = $invoice->kind === 'retail' ? PriceRounding::round($eligible * (float) $invoice->end_discount_percent / 100) : 0.0;
        $delivery = (float) $invoice->delivery_charge;

        return ['subtotal' => $subtotal, 'discount' => $discount, 'delivery' => $delivery, 'total' => round($subtotal - $discount + $delivery, 2), 'eligible' => $eligible];
    }

    /**
     * Change who a draft is for (spec ch 8). Same kind of customer, and every line that has no price of its own is priced again at
     * the new customer's rate.
     *
     * @throws StockException
     */
    public function changeCustomer(Invoice $invoice, User $customer, User $by): Invoice
    {
        $this->guard($invoice, 'piece', 1);

        if ($invoice->kind === 'retail') {
            throw new StockException('A retail sale has no customer to change.');
        }

        $type = $invoice->kind === 'wholesale' ? AccountType::Wholesaler : AccountType::Paikari;
        if ($customer->account_type !== $type) {
            throw new StockException('Pick a customer of the same kind.');
        }

        return DB::transaction(function () use ($invoice, $customer) {
            $invoice->update(['customer_id' => $customer->id, 'invoice_percent' => null]);
            $fresh = $invoice->fresh('customer');

            foreach ($fresh->lines()->get() as $line) {
                if ($line->special_percent !== null || $line->offer_product_id !== null || $line->is_outside) {
                    continue;
                }
                $rate = $this->rate($fresh, $line->medicine, $line->unit);
                $line->update(['unit_price' => $rate['price'], 'rate_layer' => $rate['layer'], 'percent_off' => $rate['percent']]);
            }
            $this->touch($invoice);

            return $invoice->fresh();
        });
    }

    /**
     * Give one line a percent of its own, below the invoice's (spec 6.2: "a separate lower percentage on them"). The same
     * rules as any percent: within the staff limit, and never below cost for staff.
     *
     * @throws StockException
     */
    public function setLinePercent(InvoiceLine $line, float $percent, User $by): InvoiceLine
    {
        $invoice = $line->invoice;
        $this->guard($invoice, $line->unit, 1);

        if ($percent < 0 || $percent >= 100) {
            throw new StockException('A discount is between 0 and 100 percent.');
        }

        $this->checkDiscountLimit($by, $percent);

        $medicine = $line->medicine;
        $mrp = (float) $line->unit_mrp;
        $price = PriceRounding::round($mrp * (1 - $percent / 100));

        if ($by->account_type !== AccountType::Admin && $this->stock->isLoss($medicine, round($price / self::fraction($medicine, $line->unit), 4))) {
            app(ApprovalService::class)->noteAttempt($by, 'set a price below cost on '.$medicine->brand_name);

            throw new StockException('This price needs the admin.');
        }

        return DB::transaction(function () use ($line, $percent, $price, $invoice, $by) {
            $line->update(['unit_price' => $price, 'rate_layer' => 'special', 'special_percent' => $percent, 'percent_off' => $percent, 'held_for_loss' => false]);
            $this->retake($invoice, $line->fresh(), $by);
            $this->touch($invoice);

            return $line->fresh();
        });
    }

    /** Goods already taken for a line are taken again at its new price, so the profit record uses the price it really sold at. */
    private function retake(Invoice $invoice, InvoiceLine $line, ?User $by): void
    {
        if ($invoice->channel === 'counter' || in_array($line->verify_status, ['full', 'partial'], true)) {
            $this->stock->restore($line->id, $by);
            $this->take($invoice, $line->fresh(), $line->medicine, $by);
        }
    }

    /** Retail only: the one discount at the bottom, within the staff limit. */
    public function setEndDiscount(Invoice $invoice, float $percent, User $by): Invoice
    {
        $this->guard($invoice, 'piece', 1);

        if ($invoice->kind !== 'retail') {
            throw new StockException('The end discount is for retail sales only.');
        }

        if ($percent < 0 || $percent > 100) {
            throw new StockException('A discount is between 0 and 100 percent.');
        }

        $this->checkDiscountLimit($by, $percent);

        // Staff can never sell at a loss (spec 6.2): the end discount comes off every line that has no price of its own, so each of them is checked at
        // the price it would end up at. The admin is told when staff try, and only the admin may go on.
        if ($by->account_type !== AccountType::Admin && $percent > 0) {
            foreach ($invoice->lines()->with('medicine')->whereNull('removed_at')->whereNull('special_percent')->get() as $line) {
                $after = (float) $line->unit_price * (1 - $percent / 100);

                if ($this->stock->isLoss($line->medicine, round($after / self::fraction($line->medicine, $line->unit), 4))) {
                    app(ApprovalService::class)->noteAttempt($by, 'set an end discount that sells '.$line->medicine->brand_name.' below cost');

                    throw new StockException('This discount sells something below what it cost. Only the admin can do that.');
                }
            }
        }

        $invoice->update(['end_discount_percent' => $percent]);
        $this->touch($invoice);

        return $invoice->fresh();
    }

    /** Paikari or wholesale: one % for the whole invoice. Lines already priced keep their price unless repriced. */
    public function setInvoicePercent(Invoice $invoice, ?float $percent, User $by): Invoice
    {
        $this->guard($invoice, 'piece', 1);

        if ($invoice->kind === 'retail') {
            throw new StockException('A retail invoice has an end discount, not an invoice %.');
        }

        $this->checkDiscountLimit($by, $percent);

        return DB::transaction(function () use ($invoice, $percent, $by) {
            $invoice->update(['invoice_percent' => $percent]);
            $fresh = $invoice->fresh('customer');

            foreach ($invoice->lines()->get() as $line) {
                if ($line->special_percent !== null) {
                    continue;
                }
                $rate = $this->rate($fresh, $line->medicine, $line->unit);

                // The whole discount is not blocked because a few lines would lose money: those keep their price and wait for a
                // lower percent of their own. The rest get the invoice's percent. Only the admin may sell at a loss (spec 6.2).
                $perStockUnit = round($rate['price'] / self::fraction($line->medicine, $line->unit), 4);
                if ($by->account_type !== AccountType::Admin && $this->stock->isLoss($line->medicine, $perStockUnit)) {
                    $line->update(['held_for_loss' => true]);

                    continue;
                }

                $line->update(['unit_price' => $rate['price'], 'rate_layer' => $rate['layer'], 'percent_off' => $rate['percent'], 'held_for_loss' => false]);
                $this->retake($invoice, $line->fresh(), $by);
            }

            $this->touch($invoice);

            return $invoice->fresh();
        });
    }

    // ------------------------------------------------------------------ closing

    /** Finalise: freeze the totals and give the memo its number. Needs at least one line. */
    public function finalise(Invoice $invoice, User $by, string $method = 'cash', ?float $paid = null): Invoice
    {
        if ($invoice->status !== 'verified') {
            $this->guard($invoice, 'piece', 1);
        }

        if (! in_array($method, self::PAYMENT_METHODS, true)) {
            throw new StockException('Unknown payment method.');
        }

        $done = DB::transaction(function () use ($invoice, $by, $method, $paid) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->lines()->count() === 0) {
                throw new StockException('An empty invoice cannot be finalised.');
            }

            // A controlled medicine cannot be sold without the buyer, prescription and doctor written down (spec ch 27).
            if ($this->controlled->missing($invoice)) {
                throw new StockException('A controlled medicine is on this memo. Write the buyer, prescription number and doctor before finishing.');
            }

            $totals = $this->totals($invoice);
            // A token comes off first, then credit from an earlier return, marked "Return product" (spec ch 04, 12, 24). Two lines on the memo.
            $token = app(Tokens::class)->spendOnInvoice($invoice, $totals['total']);
            $credit = $this->returns->spendCredit($invoice, round($totals['total'] - $token, 2));
            $total = round($totals['total'] - $token - $credit, 2);

            // Paid in full unless typed otherwise; 'due' means nothing paid yet.
            $paid = $method === 'due' ? 0.0 : ($paid ?? $total);
            if ($paid < 0 || $paid > $total) {
                throw new StockException('The amount paid cannot be more than the total.');
            }

            $invoice->update([
                'payment_method' => $method, 'paid_amount' => $paid, 'credit_applied' => $credit, 'token_applied' => $token,
                'number' => $this->nextNumber(), 'status' => 'final', 'finalised_by' => $by->id, 'finalised_at' => now(),
                // A sale is complete once the goods are with the buyer (spec 6.0): at the counter or on collecting at once, an order we send when it arrives.
                'completed_at' => $invoice->channel === 'online' && in_array($invoice->delivery_mode, ['send', 'delivery'], true) && $invoice->delivered_at === null ? null : now(),
                'subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount'], 'total' => $total,
            ]);

            return $invoice->fresh();
        });

        $this->messages->orderDone($done);

        return $done;
    }

    /** Hours after which a counter draft that nobody has touched is asked about (spec 6.6, "a draft sits for 1-2 days"). */
    public function staleAfterHours(): int
    {
        return max(1, (int) Setting::read('drafts.stale_hours', 24));
    }

    /** A draft nobody touched for a day or two, and not put off by "Keep". Its goods are held back from everyone else. */
    public function isStale(Invoice $invoice): bool
    {
        return $invoice->status === 'draft' && $invoice->channel === 'counter'
            && ($invoice->last_activity_at ?? $invoice->opened_at)->lt(now()->subHours($this->staleAfterHours()))
            && ($invoice->stale_snooze_until === null || $invoice->stale_snooze_until->lte(now()));
    }

    /** "Keep": the goods stay held, and the question comes back after a few days. */
    public function keepOpen(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new StockException('Only an open draft can be kept.');
        }

        $invoice->update(['stale_snooze_until' => now()->addDays(max(1, (int) Setting::read('drafts.ask_again_days', 2)))]);

        return $invoice->fresh();
    }

    /** Cancel a draft: every line's stock comes back. */
    public function cancel(Invoice $invoice, ?User $by, ?string $reason = null): Invoice
    {
        if (! in_array($invoice->status, ['draft', 'placed', 'verified'], true)) {
            throw new StockException('Only an open draft can be cancelled here. A finished sale goes through returns.');
        }

        $cancelled = DB::transaction(function () use ($invoice, $by) {
            foreach ($invoice->lines()->get() as $line) {
                $this->stock->restore($line->id, $by);
            }

            $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'delivery_refund' => app(Dispatching::class)->deliveryRefundFor($invoice)]);
            app(Tokens::class)->releaseInvoice($invoice);
            app(Payments::class)->orderCancelled($invoice);

            return $invoice->fresh();
        });

        // The buyer is told when we cancel (a reason is given); when they cancel themselves they already know.
        if ($reason !== null) {
            $this->messages->orderCancelled($cancelled, $reason);
        }

        return $cancelled;
    }

    /**
     * Correct a finished memo (admin, spec ch 22). The old memo is kept in history marked Cancelled, its goods and any
     * credit it used go back, and a new draft with the same lines is opened; finishing it gives the new number.
     * A memo that already has a return against it cannot be corrected.
     */
    public function correct(Invoice $invoice, User $by): Invoice
    {
        if (! $by->can('manage-stock')) {
            throw StockException::adminOnly();
        }

        if ($invoice->status !== 'final') {
            throw new StockException('Only a finished memo can be corrected.');
        }

        if (SaleReturn::where('invoice_id', $invoice->id)->where('status', '!=', 'rejected')->exists()) {
            throw new StockException('This memo has a return against it, so it cannot be corrected.');
        }

        return DB::transaction(function () use ($invoice, $by) {
            $lines = $invoice->lines()->get();

            foreach ($lines as $line) {
                $this->stock->restore($line->id, $by);
            }

            if ((float) $invoice->credit_applied > 0) {
                CreditEntry::create(['user_id' => $invoice->customer_id, 'amount' => $invoice->credit_applied, 'reason' => 'memo_corrected', 'invoice_id' => $invoice->id]);
            }

            $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'note' => trim(($invoice->note ?? '').' Corrected.')]);
            app(Tokens::class)->releaseInvoice($invoice);

            $new = Invoice::create([
                'kind' => $invoice->kind, 'channel' => 'counter', 'status' => 'draft', 'customer_id' => $invoice->customer_id, 'label' => $invoice->label,
                'opened_by' => $by->id, 'invoice_percent' => $invoice->invoice_percent, 'end_discount_percent' => $invoice->end_discount_percent,
                'delivery_mode' => $invoice->delivery_mode, 'delivery_charge' => $invoice->delivery_charge, 'note' => 'Corrects '.$invoice->number,
                'opened_at' => now(), 'last_activity_at' => now(), 'corrects_invoice_id' => $invoice->id,
            ]);

            foreach ($lines as $line) {
                $copy = $line->replicate(['removed_at', 'requested_quantity', 'verify_status']);
                $copy->invoice_id = $new->id;
                $copy->save();
                $this->controlled->copyForward($line, $copy);
                $this->take($new, $copy, $copy->medicine, $by);
            }

            return $new->fresh();
        });
    }

    // ------------------------------------------------------------------ online orders

    /** The buyer sends the cart. Nothing is promised yet: every line waits for staff to check it. */
    public function place(Invoice $cart): Invoice
    {
        if ($cart->channel !== 'online' || $cart->status !== 'draft') {
            throw new StockException('This invoice is closed.');
        }

        if ($cart->lines()->count() === 0) {
            throw new StockException('Add at least one medicine first.');
        }

        $placed = DB::transaction(function () use ($cart) {
            foreach ($cart->lines()->get() as $line) {
                $line->update(['requested_quantity' => $line->quantity, 'verify_status' => 'pending']);
            }
            $cart->update(['status' => 'placed', 'placed_at' => now(), 'last_activity_at' => now()]);

            return $cart->fresh();
        });

        $this->messages->orderReceived($placed);

        return $placed;
    }

    /**
     * Staff check one line: full, partial (a smaller quantity) or not available. The stock is taken here, at
     * the check, and re-checking a line first puts back what the earlier check took.
     */
    public function verifyLine(InvoiceLine $line, string $status, ?float $quantity, User $by, ?string $expected = null): InvoiceLine
    {
        $invoice = $line->invoice;

        if ($invoice->status !== 'placed') {
            throw new StockException('This order is not waiting to be checked.');
        }

        if (! in_array($status, ['full', 'partial', 'unavailable'], true)) {
            throw new StockException('Pick full, partial or not available.');
        }

        // First click wins (spec ch 7): when the screen was showing an older state of this line, someone else got there first.
        if ($expected !== null && ($line->verify_status ?? 'pending') !== $expected) {
            $who = $line->checked_by ? User::whereKey($line->checked_by)->value('name') : null;

            throw new StockException($who ? __('{name} already checked this line. The screen now shows it.', ['name' => $who]) : __('Someone already checked this line. The screen now shows it.'));
        }

        $firstCheck = ! $this->pickingStarted($invoice);
        $requested = (float) ($line->requested_quantity ?? $line->quantity);
        $qty = match ($status) {
            'full' => $requested,
            'partial' => $quantity,
            default => 0.0,
        };

        if ($status === 'partial' && ($qty === null || $qty <= 0 || $qty >= $requested)) {
            throw new StockException('A partial quantity is more than zero and less than what was asked for.');
        }

        $checked = DB::transaction(function () use ($line, $invoice, $status, $qty, $by) {
            $medicine = $line->medicine;
            $this->stock->restore($line->id, $by);

            $line->update(['verify_status' => $status, 'quantity' => $qty, 'stock_units' => round($qty * self::fraction($medicine, $line->unit), 4), 'checked_by' => $by->id, 'checked_at' => now()]);
            if ($qty > 0) {
                $this->repriceOfferLine($line, $invoice, (int) round($qty));
            }

            if ($qty > 0) {
                $this->take($invoice, $line, $medicine, $by);
            }
            $this->touch($invoice);

            return $line->fresh();
        });

        if ($firstCheck) {
            $this->messages->orderPicking($invoice);
        }

        if ($status === 'unavailable') {
            // Spec ch 7, step 5: what could not be supplied goes to the short list in PharmaTrack. The check itself must never fail over it.
            try {
                app(PharmaTrack::class)->announceMissed($checked, $requested);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $checked;
    }

    /**
     * Staff edit a line's price before the order goes back to the buyer (spec 4.4.4). Below cost it is refused
     * for staff, in plain words that do not reveal the cost; only the admin may go there.
     */
    public function setLinePrice(InvoiceLine $line, float $price, User $by): InvoiceLine
    {
        $invoice = $line->invoice;

        if (! in_array($invoice->status, ['draft', 'placed'], true)) {
            throw new StockException('This invoice is closed.');
        }

        if ($price <= 0) {
            throw new StockException('A price must be more than zero.');
        }

        $medicine = $line->medicine;

        if ($by->account_type !== AccountType::Admin && $this->stock->isLoss($medicine, round($price / self::fraction($medicine, $line->unit), 4))) {
            app(ApprovalService::class)->noteAttempt($by, 'set a price below cost on '.$medicine->brand_name);

            throw new StockException('This price needs the admin.');
        }

        return DB::transaction(function () use ($line, $price, $by, $invoice, $medicine) {
            $mrp = (float) $line->unit_mrp;
            $line->update(['unit_price' => $price, 'rate_layer' => 'manual', 'percent_off' => $mrp > 0 ? round(max(0, (1 - $price / $mrp) * 100), 3) : 0]);

            // Stock already taken at the old price is re-taken so the profit record uses the new one.
            if (in_array($line->verify_status, ['full', 'partial'], true)) {
                $this->stock->restore($line->id, $by);
                $this->take($invoice, $line->fresh(), $medicine, $by);
            }
            $this->touch($invoice);

            return $line->fresh();
        });
    }

    /**
     * The buyer adds more after seeing the memo (spec 4.4 step 8). The new line goes through the same check as the
     * rest, so a checked order is reopened for staff; the lines already checked and their stock stay as they were.
     */
    public function addMore(Invoice $order, Medicine $medicine, string $unit, float $quantity): InvoiceLine
    {
        if ($order->channel !== 'online' || ! in_array($order->status, ['placed', 'verified'], true)) {
            throw new StockException('This invoice is closed.');
        }

        if (! in_array($unit, self::UNITS, true)) {
            throw new StockException('Unknown unit.');
        }

        if ($quantity <= 0) {
            throw new StockException('The quantity must be more than zero.');
        }

        return DB::transaction(function () use ($order, $medicine, $unit, $quantity) {
            $rate = $this->rate($order, $medicine, $unit);
            $line = InvoiceLine::create([
                'invoice_id' => $order->id, 'medicine_id' => $medicine->id, 'unit' => $unit, 'quantity' => $quantity, 'requested_quantity' => $quantity,
                'verify_status' => 'pending', 'stock_units' => round($quantity * self::fraction($medicine, $unit), 4),
                'unit_mrp' => $rate['mrp'], 'unit_price' => $rate['price'], 'rate_layer' => $rate['layer'], 'percent_off' => $rate['percent'],
            ]);

            if ($order->status === 'verified') {
                $order->update(['status' => 'placed']);
            }
            $this->touch($order);

            return $line;
        });
    }

    /** True once staff have checked at least one line: from then on the shop can only ask to cancel. */
    public function pickingStarted(Invoice $order): bool
    {
        return $order->allLines()->whereIn('verify_status', ['full', 'partial', 'unavailable'])->exists();
    }

    /** The shop asks to cancel an order staff have already started on. Staff decide. */
    public function requestCancel(Invoice $order): Invoice
    {
        if ($order->channel !== 'online' || ! in_array($order->status, ['placed', 'verified'], true)) {
            throw new StockException('Only an open draft can be cancelled here. A finished sale goes through returns.');
        }

        $order->update(['cancel_requested_at' => $order->cancel_requested_at ?? now()]);

        return $order->fresh();
    }

    public function declineCancel(Invoice $order): Invoice
    {
        $order->update(['cancel_requested_at' => null]);

        return $order->fresh();
    }

    /** Packed but never collected: the whole order is refunded and the stock goes back (spec 4.4.9). */
    public function notCollected(Invoice $order, User $by): Invoice
    {
        if ($order->status !== 'verified') {
            throw new StockException('Only a checked order can be marked as not collected.');
        }

        $order->update(['note' => trim(($order->note ?? '').' Not collected.')]);

        return $this->cancel($order, $by, __('It was not collected in time.'));
    }

    /** Every line is checked: the order becomes a memo the buyer can see and the counter can finish. */
    public function completeVerification(Invoice $invoice, User $by): Invoice
    {
        if ($invoice->status !== 'placed') {
            throw new StockException('This order is not waiting to be checked.');
        }

        $lines = $invoice->lines()->get();

        if ($lines->contains(fn (InvoiceLine $l) => $l->verify_status === 'pending')) {
            throw new StockException('Check every line first.');
        }

        if ($lines->every(fn (InvoiceLine $l) => $l->verify_status === 'unavailable')) {
            $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'note' => 'Nothing was available.']);
            $this->messages->orderCancelled($invoice->fresh(), __('Nothing you asked for was available.'));

            return $invoice->fresh();
        }

        $totals = $this->totals($invoice);
        $invoice->update(['status' => 'verified', 'verified_at' => now(), 'subtotal' => $totals['subtotal'], 'total' => $totals['total'], 'last_activity_at' => now()]);
        $this->messages->orderChecked($invoice->fresh());

        return $invoice->fresh();
    }

    // ------------------------------------------------------------------ internals

    private function take(Invoice $invoice, InvoiceLine $line, Medicine $medicine, ?User $by): void
    {
        // A buyer's cart never touches stock; it is taken line by line when staff check the order.
        if ($invoice->channel === 'online' && $invoice->status === 'draft') {
            return;
        }

        if ($line->is_outside || (float) $line->stock_units <= 0) {
            return;
        }

        $kind = $invoice->kind === 'retail' ? StockService::SALE_RETAIL : StockService::SALE_PAIKARI;
        $perStockUnit = round((float) $line->unit_price / self::fraction($medicine, $line->unit), 4);

        if ($line->offer_product_id && $line->offer) {
            // Offer sales always come off the stock, and only from the lots on offer (spec 9.1).
            $this->stock->sell($medicine, (float) $line->stock_units, $perStockUnit, StockService::SALE_OFFER, $by, $line->id, $this->offers->lotIds($line->offer));

            return;
        }

        $this->stock->sell($medicine, (float) $line->stock_units, $perStockUnit, $kind, $by, $line->id);
    }

    private function guard(Invoice $invoice, string $unit, float $quantity): void
    {
        if (! $invoice->isDraft()) {
            throw new StockException('This invoice is closed.');
        }

        if (! in_array($unit, self::UNITS, true)) {
            throw new StockException('Unknown unit.');
        }

        if ($quantity <= 0) {
            throw new StockException('The quantity must be more than zero.');
        }
    }

    /** LOS-4: staff may not discount past their limit. Above it needs the admin (the one-tap approval flow comes later). */
    private function checkDiscountLimit(?User $by, ?float $percent): void
    {
        if ($percent === null || $by?->account_type === AccountType::Admin) {
            return;
        }

        if ($by === null) {
            throw new StockException('Only staff can give a discount.');
        }

        // A limit set for this staff member beats the role-wide one (spec 6.2).
        $limit = $by->discount_limit !== null ? (float) $by->discount_limit : (float) Setting::read('counter.staff_discount_limit', 5);

        if ($percent > $limit) {
            app(ApprovalService::class)->noteAttempt($by, "asked for {$percent}% (limit {$limit}%)");

            throw new StockException("A discount above {$limit}% needs the admin.");
        }
    }

    private function touch(Invoice $invoice): void
    {
        $invoice->forceFill(['last_activity_at' => now()])->save();
    }

    private function nextNumber(): string
    {
        // The year is part of the number (spec ch 22) and counter and online share one sequence, so numbers never collide.
        $prefix = 'M'.now()->year.'-';
        $last = Invoice::where('number', 'like', $prefix.'%')->orderByDesc('number')->lockForUpdate()->value('number');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
