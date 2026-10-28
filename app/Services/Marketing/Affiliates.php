<?php

namespace App\Services\Marketing;

use App\Models\Affiliate;
use App\Models\AffiliateEarning;
use App\Models\AffiliatePayout;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Affiliates (spec ch 27): someone outside the business who sends buyers to the site and earns a share of what those buyers spend. The owner opens an affiliate
 * with a code and a percent; the affiliate shares the link /a/CODE; whoever opens it carries the code for a number of days (a setting) and the orders they place
 * in that time are marked as the affiliate's. When an order is complete (a shop memo finished, a market order delivered) the affiliate earns the percent of
 * the goods, delivery not counted; when it is cancelled or returned the earning is cancelled, or taken back from the next payout if it was paid already. The owner
 * pays what is owed and writes down how. A buyer's own link never pays them for their own orders.
 */
class Affiliates
{
    public const COOKIE = 'aff';

    public function __construct(private ActivityLogger $activity) {}

    /** Days the code stays on a visitor. */
    public function days(): int
    {
        return max(1, (int) Setting::read('affiliate.days', 30));
    }

    public function link(Affiliate $a): string
    {
        return url('/a/'.$a->code);
    }

    // ------------------------------------------------------------------ the owner's side

    /**
     * @param  array<string, mixed>  $in  name, phone, percent, note and, if wanted, a code of their own
     *
     * @throws StockException
     */
    public function create(array $in, User $by): Affiliate
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        $percent = round((float) ($in['percent'] ?? 0), 2);
        $phone = preg_replace('/\D+/', '', (string) ($in['phone'] ?? '')) ?: null;

        if (mb_strlen($name) < 2) {
            throw new StockException('Write the name of the affiliate.');
        }

        if ($percent <= 0 || $percent > 50) {
            throw new StockException('The share is a percent above 0 and up to 50.');
        }

        if ($phone !== null && ! preg_match('/^01[3-9]\d{8}$/', $phone)) {
            throw new StockException('Write a Bangladesh mobile number, like 01712345678.');
        }

        $code = strtoupper(trim((string) ($in['code'] ?? '')));

        if ($code !== '' && ! preg_match('/^[A-Z0-9]{3,24}$/', $code)) {
            throw new StockException('A code is 3 to 24 letters and numbers, with no spaces.');
        }

        $user = $phone ? User::where('phone', $phone)->first() : null;

        if ($user && Affiliate::where('user_id', $user->id)->exists()) {
            throw new StockException('That person is already an affiliate.');
        }

        if ($code !== '' && Affiliate::where('code', $code)->exists()) {
            throw new StockException('That code is taken. Choose another.');
        }

        $affiliate = Affiliate::create([
            'user_id' => $user?->id, 'name' => $name, 'phone' => $phone, 'code' => $code !== '' ? $code : $this->freshCode($name), 'percent' => $percent, 'active' => true,
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 200) ?: null,
        ]);
        $this->activity->record('affiliate.created', $affiliate, ['percent' => $percent, 'linked' => $user !== null], $by);

        return $affiliate;
    }

    /**
     * @param  array<string, mixed>  $in
     *
     * @throws StockException
     */
    public function update(Affiliate $a, array $in, User $by): Affiliate
    {
        $changes = [];

        if (array_key_exists('percent', $in)) {
            $percent = round((float) $in['percent'], 2);

            if ($percent <= 0 || $percent > 50) {
                throw new StockException('The share is a percent above 0 and up to 50.');
            }

            // A new share counts for orders from now on; what is already earned keeps the share it had.
            $changes['percent'] = $percent;
        }

        if (array_key_exists('active', $in)) {
            $changes['active'] = (bool) $in['active'];
        }

        if (array_key_exists('note', $in)) {
            $changes['note'] = mb_substr(trim((string) $in['note']), 0, 200) ?: null;
        }

        $a->update($changes);
        $this->activity->record('affiliate.updated', $a, $changes, $by);

        return $a;
    }

    /** Pay what is owed in one go and write down how. @throws StockException */
    public function pay(Affiliate $a, string $method, ?string $note, User $by): AffiliatePayout
    {
        if (! in_array($method, ['cash', 'bkash', 'nagad', 'rocket', 'bank'], true)) {
            throw new StockException('Pick how it was paid.');
        }

        return DB::transaction(function () use ($a, $method, $note, $by) {
            Affiliate::whereKey($a->id)->lockForUpdate()->first();
            $rows = AffiliateEarning::where('affiliate_id', $a->id)->where('status', 'owed')->get();
            $owed = round((float) $rows->sum('amount'), 2);

            if ($owed <= 0) {
                throw new StockException('Nothing is owed to this affiliate.');
            }

            $payout = AffiliatePayout::create(['affiliate_id' => $a->id, 'amount' => $owed, 'method' => $method, 'note' => $note ? mb_substr(trim($note), 0, 200) : null, 'paid_by' => $by->id, 'paid_at' => now()]);
            AffiliateEarning::whereKey($rows->modelKeys())->update(['status' => 'paid', 'payout_id' => $payout->id]);
            $this->activity->record('affiliate.paid', $a, ['amount' => $owed, 'method' => $method], $by);

            return $payout;
        });
    }

    /**
     * Every affiliate with how they are doing, for the owner's list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(): Collection
    {
        $sums = AffiliateEarning::query()->selectRaw("affiliate_id, sum(case when status = 'owed' then amount else 0 end) as owed, sum(case when status = 'paid' then amount else 0 end) as paid, sum(case when kind = 'earn' and status != 'void' then 1 else 0 end) as orders")->groupBy('affiliate_id')->get()->keyBy('affiliate_id');

        return Affiliate::orderByDesc('active')->orderBy('name')->get()->map(fn (Affiliate $a) => [
            'id' => $a->id, 'name' => $a->name, 'phone' => $a->phone, 'code' => $a->code, 'percent' => (float) $a->percent, 'active' => $a->active, 'clicks' => $a->clicks, 'linked' => $a->user_id !== null,
            'orders' => (int) ($sums[$a->id]->orders ?? 0), 'owed' => round((float) ($sums[$a->id]->owed ?? 0), 2), 'paid' => round((float) ($sums[$a->id]->paid ?? 0), 2),
            'link' => $this->link($a), 'note' => $a->note,
        ]);
    }

    /**
     * One affiliate's own account: the figures, the link and what each order earned.
     *
     * @return array<string, mixed>
     */
    public function statement(Affiliate $a): array
    {
        $row = $this->overview()->firstWhere('id', $a->id);
        $earnings = AffiliateEarning::where('affiliate_id', $a->id)->latest('id')->limit(60)->get();

        return $row + [
            'days' => $this->days(),
            'earnings' => $earnings->map(fn (AffiliateEarning $e) => ['id' => $e->id, 'kind' => $e->kind, 'status' => $e->status, 'base' => (float) $e->base, 'percent' => (float) $e->percent, 'amount' => (float) $e->amount, 'at' => $e->created_at->toIso8601String()])->all(),
            'payouts' => AffiliatePayout::where('affiliate_id', $a->id)->latest('id')->limit(20)->get()->map(fn (AffiliatePayout $p) => ['id' => $p->id, 'amount' => (float) $p->amount, 'method' => $p->method, 'note' => $p->note, 'at' => $p->paid_at->toIso8601String()])->all(),
        ];
    }

    // ------------------------------------------------------------------ the visitor's side

    /** Someone opened the link: count it, and give them the code for a while. Returns the code to keep, or nothing when the link is not one we know. */
    public function click(string $code): ?string
    {
        $a = Affiliate::where('code', strtoupper($code))->where('active', true)->first();

        if ($a === null) {
            return null;
        }

        Affiliate::whereKey($a->id)->increment('clicks');

        return $a->code;
    }

    /** The affiliate whose code this visitor carries, if still active. A buyer is never their own affiliate's customer. */
    public function current(?int $buyerId = null): ?Affiliate
    {
        $code = request()?->cookie(self::COOKIE);

        if (! is_string($code) || ! preg_match('/^[A-Z0-9]{3,24}$/', $code)) {
            return null;
        }

        $a = Affiliate::where('code', $code)->where('active', true)->first();

        return $a && ($buyerId === null || $a->user_id !== $buyerId) ? $a : null;
    }

    // ------------------------------------------------------------------ earning

    /** A shop memo from the website that is finished earns its affiliate their share of the goods, once. */
    public function earnOnInvoice(Invoice $invoice): void
    {
        if ($invoice->affiliate_id === null || $invoice->completed_at === null) {
            return;
        }

        $this->earn((int) $invoice->affiliate_id, 'invoice', $invoice->id, (float) $invoice->total - (float) $invoice->delivery_charge);
    }

    public function earnOnMarketOrder(MarketOrder $order): void
    {
        if ($order->affiliate_id === null) {
            return;
        }

        $this->earn((int) $order->affiliate_id, 'market', $order->id, (float) $order->total - (float) $order->delivery_charge);
    }

    private function earn(int $affiliateId, string $type, int $id, float $base): void
    {
        $a = Affiliate::find($affiliateId);

        if ($a === null || $base <= 0) {
            return;
        }

        try {
            AffiliateEarning::create(['affiliate_id' => $a->id, 'kind' => 'earn', 'source_type' => $type, 'source_id' => $id, 'base' => round($base, 2), 'percent' => $a->percent, 'amount' => round($base * (float) $a->percent / 100, 2), 'status' => 'owed']);
        } catch (UniqueConstraintViolationException) {
            // This order has paid its share already.
        }
    }

    /** An order that did not stay a sale: what it earned is cancelled, or taken back from the next payout if it was paid already. */
    public function cancelOnInvoice(Invoice $invoice): void
    {
        $this->undo($invoice->affiliate_id, 'invoice', $invoice->id);
    }

    public function cancelOnMarketOrder(MarketOrder $order): void
    {
        $this->undo($order->affiliate_id, 'market', $order->id);
    }

    private function undo(?int $affiliateId, string $type, int $id): void
    {
        if ($affiliateId === null) {
            return;
        }

        $earned = AffiliateEarning::where(['source_type' => $type, 'source_id' => $id, 'kind' => 'earn'])->first();

        if ($earned === null || $earned->status === 'void') {
            return;
        }

        if ($earned->status === 'owed') {
            $earned->update(['status' => 'void']);

            return;
        }

        $this->takeBack($earned->affiliate_id, $type, $id, (float) $earned->base, (float) $earned->percent);
    }

    /** Goods came back: the share of that money is taken back, once for each return. */
    public function takeBackReturn(SaleReturn $return): void
    {
        $affiliateId = Invoice::whereKey($return->invoice_id)->value('affiliate_id');

        if ($affiliateId === null) {
            return;
        }

        $earned = AffiliateEarning::where(['source_type' => 'invoice', 'source_id' => $return->invoice_id, 'kind' => 'earn'])->first();

        if ($earned === null || $earned->status === 'void' || (float) $return->amount <= 0) {
            return;
        }

        $this->takeBack((int) $affiliateId, 'return', $return->id, min((float) $return->amount, (float) $earned->base), (float) $earned->percent);
    }

    private function takeBack(int $affiliateId, string $type, int $id, float $base, float $percent): void
    {
        try {
            AffiliateEarning::create(['affiliate_id' => $affiliateId, 'kind' => 'takeback', 'source_type' => $type, 'source_id' => $id, 'base' => round($base, 2), 'percent' => $percent, 'amount' => -round($base * $percent / 100, 2), 'status' => 'owed']);
        } catch (UniqueConstraintViolationException) {
            // Taken back already.
        }
    }

    private function freshCode(string $name): string
    {
        $stem = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($name)) ?: 'AFF', 0, 6));

        do {
            $code = $stem.strtoupper(Str::random(3));
        } while (Affiliate::where('code', $code)->exists());

        return $code;
    }
}
