<?php

namespace App\Services\Custom;

use App\Models\CustomMaterial;
use App\Models\CustomOrder;
use App\Models\CustomOrderEvent;
use App\Models\CustomProgress;
use App\Models\MeasurementProfile;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\ImageCompressor;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Made-to-order work in wood and clothing (spec ch 16). The buyer gives the measurements and picks the wood or fabric; we make the memo
 * directly (material, wages, total, advance, days); both sides confirm the measurements; work starts once the advance is in and the wages
 * are set. Nothing here is a return or a commission: it is made for one person, and it is our own business.
 */
class CustomOrders
{
    public function __construct(private MeasurementProfiles $profiles, private Messenger $messages, private ImageCompressor $images) {}

    public function defaultAdvance(): int
    {
        return max(0, min(100, (int) Setting::read('custom.advance_percent', 30)));
    }

    public function urgentPercent(): float
    {
        return max(0, (float) Setting::read('custom.urgent_percent', 20));
    }

    public function pickupDays(): int
    {
        return max(1, (int) Setting::read('custom.pickup_days', 7));
    }

    // ------------------------------------------------------------------ the buyer orders

    /**
     * @param  array{kind: string, material_id?: ?int, fabric_by?: ?string, items: array, details?: array, note?: ?string, urgent?: bool}  $data
     *
     * @throws StockException
     */
    public function place(User $buyer, array $data, ?UploadedFile $photo = null): CustomOrder
    {
        $kind = (string) ($data['kind'] ?? '');

        if (! in_array($kind, (array) config('custom.kinds'), true)) {
            throw new StockException('Choose wood or clothing.');
        }

        // Made to order is a choice made for the kind of business when the owner opens it (spec ch 15). With no such sector opened, it is open as before.
        $sector = Sector::where('slug', $kind)->first();
        if ($sector && ! $sector->custom_orders) {
            throw new StockException('We are not taking made-to-order jobs of this kind now.');
        }

        $material = null;
        $fabricBy = null;

        if ($kind === 'wood') {
            $material = $this->material((int) ($data['material_id'] ?? 0), 'wood');
        } else {
            $fabricBy = $data['fabric_by'] ?? null;
            if (! in_array($fabricBy, ['shop', 'buyer'], true)) {
                throw new StockException('Say whether you bring the fabric or we do.');
            }
            // The price changes with the fabric only when we supply it.
            $material = $fabricBy === 'shop' ? $this->material((int) ($data['material_id'] ?? 0), 'fabric') : null;
        }

        $items = $kind === 'wood' ? $this->woodItems($data['items'] ?? []) : $this->clothingItems($buyer, $data['items'] ?? []);

        $order = CustomOrder::create([
            'public_token' => Str::random(40), 'buyer_id' => $buyer->id, 'kind' => $kind, 'material_id' => $material?->id, 'material_name' => $material?->name, 'material_rate' => $material?->rate,
            'fabric_by' => $fabricBy, 'items' => $items, 'details' => $this->details($kind, (array) ($data['details'] ?? [])),
            'note' => ! empty($data['note']) ? mb_substr(trim((string) $data['note']), 0, 1000) : null, 'urgent' => (bool) ($data['urgent'] ?? false), 'status' => 'requested',
            'photo_path' => $photo ? $this->images->store($photo, 'custom') : null,
        ]);

        $this->log($order, $buyer, 'placed', 'The buyer gave these measurements.');
        $this->tellStaff($buyer, 'asks for a made-to-order job');

        return $order;
    }

    /**
     * The buyer sends new measurements (from the chat) while nothing is agreed yet. A price already made has to be looked at again.
     *
     * @throws StockException
     */
    public function replaceItems(CustomOrder $order, User $buyer, array $items): CustomOrder
    {
        $this->mustBeBuyers($order, $buyer);

        if (! in_array($order->status, ['requested', 'priced'], true)) {
            throw new StockException('The measurements are agreed. Tell us in the chat if something is wrong.');
        }

        $clean = $order->kind === 'wood' ? $this->woodItems($items) : $this->clothingItems($buyer, $items);
        $order->update(['items' => $clean, 'status' => 'requested', 'priced_at' => null]);
        $this->log($order, $buyer, 'measurements', 'The buyer sent new measurements.');
        $this->tellStaff($buyer, 'sent new measurements');

        return $order->fresh();
    }

    // ------------------------------------------------------------------ our memo

    /**
     * @param  array{material_cost: float, wages_total: float, base_price: float, advance_percent?: ?int, days_needed: int, memo_note?: ?string}  $memo
     *
     * @throws StockException
     */
    public function price(CustomOrder $order, User $by, array $memo): CustomOrder
    {
        if (! in_array($order->status, ['requested', 'priced'], true)) {
            throw new StockException('This job has gone past the price.');
        }

        $base = (float) $memo['base_price'];
        $advance = (int) ($memo['advance_percent'] ?? $this->defaultAdvance());

        if ($base <= 0) {
            throw new StockException('The price must be more than zero.');
        }

        // An offer made in the chat sets the price and the days; the costs stay as they were until we set them.
        $cost = $memo['material_cost'] ?? null;
        $wages = $memo['wages_total'] ?? null;

        if (($cost !== null && (float) $cost < 0) || ($wages !== null && (float) $wages < 0)) {
            throw new StockException('The material cost and the wages cannot be less than zero.');
        }

        if ($advance < 0 || $advance > 100) {
            throw new StockException('The advance is a percent from 0 to 100.');
        }

        if ((int) $memo['days_needed'] < 1) {
            throw new StockException('Say how many days it takes.');
        }

        $qty = $memo['material_qty'] ?? null;

        if ($qty !== null && (float) $qty < 0) {
            throw new StockException('The material cost and the wages cannot be less than zero.');
        }

        $fee = $order->urgent ? round($base * $this->urgentPercent() / 100, 2) : 0.0;

        $order->update([
            'status' => 'priced', 'material_qty' => $qty !== null ? round((float) $qty, 2) : $order->material_qty, 'material_cost' => $cost !== null ? round((float) $cost, 2) : $order->material_cost, 'wages_total' => $wages !== null ? round((float) $wages, 2) : $order->wages_total, 'base_price' => $base, 'urgent_fee' => $fee,
            'price' => round($base + $fee, 2), 'advance_percent' => $advance, 'days_needed' => (int) $memo['days_needed'], 'priced_at' => now(),
            'memo_note' => ! empty($memo['memo_note']) ? mb_substr(trim((string) $memo['memo_note']), 0, 300) : null,
        ]);
        $this->log($order, $by, 'priced', 'We checked the measurements and made the memo.');
        $this->messages->send($order->buyer, 'custom_priced', ['name' => $order->number()], '/custom/'.$order->public_token);

        return $order->fresh();
    }

    /** The buyer confirms: the measurements are checked and the price is agreed. From here it is their responsibility. */
    public function confirm(CustomOrder $order, User $buyer, bool $checked): CustomOrder
    {
        $this->mustBeBuyers($order, $buyer);

        if ($order->status !== 'priced') {
            throw new StockException('There is nothing to confirm yet.');
        }

        if (! $checked) {
            throw new StockException('Tick that you have checked the measurements.');
        }

        $order->update(['status' => 'confirmed', 'buyer_confirmed_at' => now()]);
        $this->log($order, $buyer, 'confirmed', 'The buyer checked the measurements and agreed to the price.');
        $this->tellStaff($buyer, 'confirmed a made-to-order job');

        return $order->fresh();
    }

    // ------------------------------------------------------------------ making it

    /** The advance is received (it goes to the maker, who needs money to start). */
    public function receiveAdvance(CustomOrder $order, User $by, float $amount): CustomOrder
    {
        if (! in_array($order->status, ['confirmed', 'making', 'trial'], true)) {
            throw new StockException('The advance is taken once the buyer has confirmed.');
        }

        if ($amount <= 0 || (float) $order->advance_paid + $amount > (float) $order->price + 0.001) {
            throw new StockException('Check the amount.');
        }

        $order->update(['advance_paid' => round((float) $order->advance_paid + $amount, 2)]);
        $this->log($order, $by, 'advance', 'Advance received.');

        return $order->fresh();
    }

    /** @throws StockException */
    public function start(CustomOrder $order, User $by): CustomOrder
    {
        if ($order->status !== 'confirmed') {
            throw new StockException('Work starts once the buyer has confirmed.');
        }

        // Wages first: work must not begin before they are agreed (spec ch 16.7). And the advance must be in.
        if ($order->wages_total === null) {
            throw new StockException('Set the wages before the work starts.');
        }

        if ($order->craftsman_id === null) {
            throw new StockException('Give the job to a craftsman before the work starts.');
        }

        if ($order->advanceDue() > 0) {
            throw new StockException('The advance has not been received yet.');
        }

        DB::transaction(function () use ($order) {
            // The wood or fabric used comes off the stock, so the accounts match (spec ch 16). A material the owner does not count is left alone.
            $used = (float) $order->material_qty;

            if ($used > 0 && $order->material_id && ($material = CustomMaterial::whereKey($order->material_id)->lockForUpdate()->first()) && $material->stock !== null) {
                if ((float) $material->stock + 0.00001 < $used) {
                    throw new StockException('There is not enough of this wood or fabric in stock.');
                }
                $material->update(['stock' => round((float) $material->stock - $used, 2)]);
                $order->material_used_at = now();
            }

            $order->update(['status' => 'making', 'started_at' => now(), 'due_on' => today()->addDays((int) $order->days_needed)]);
        });
        $this->log($order, $by, 'started', 'Work started.');
        $this->messages->send($order->buyer, 'custom_started', ['name' => $order->number()], '/custom/'.$order->public_token);

        return $order->fresh();
    }

    /** Clothing: the buyer tries it on before it is finished. */
    public function callTrial(CustomOrder $order, User $by): CustomOrder
    {
        if ($order->kind !== 'clothing' || $order->status !== 'making') {
            throw new StockException('A trial is for clothing that is being made.');
        }

        $order->update(['status' => 'trial', 'trial_at' => now()]);
        $this->log($order, $by, 'trial', 'Ready for a trial.');
        $this->messages->send($order->buyer, 'custom_trial', ['name' => $order->number()], '/custom/'.$order->public_token);

        return $order->fresh();
    }

    public function endTrial(CustomOrder $order, User $by): CustomOrder
    {
        if ($order->status !== 'trial') {
            throw new StockException('There is no trial going on.');
        }

        $order->update(['status' => 'making']);
        $this->log($order, $by, 'trial_done', 'The trial is over. Work goes on.');

        return $order->fresh();
    }

    public function markDone(CustomOrder $order, User $by): CustomOrder
    {
        if (! in_array($order->status, ['making', 'trial'], true)) {
            throw new StockException('Only work that is being made can be finished.');
        }

        // Below 100% it is not complete: we see "70% done, 30% remaining" (spec ch 16.7).
        if ($this->progress($order) < 99.995) {
            throw new StockException(__('The work is {n}% done. It is complete only at 100%.', ['n' => $this->progress($order)]));
        }

        $order->update(['status' => 'done', 'done_at' => now()]);
        $this->log($order, $by, 'done', 'It is ready.');
        $this->messages->send($order->buyer, 'custom_done', ['name' => $order->number()], '/custom/'.$order->public_token);

        return $order->fresh();
    }

    public function collect(CustomOrder $order, User $by): CustomOrder
    {
        if ($order->status !== 'done') {
            throw new StockException('Only finished work can be handed over.');
        }

        $order->update(['status' => 'collected', 'collected_at' => now()]);
        $this->log($order, $by, 'collected', 'Handed over.');

        return $order->fresh();
    }

    /** How much of the job is done: the same percentage the wages are shared by. */
    public function progress(CustomOrder $order): float
    {
        return round((float) CustomProgress::where('custom_order_id', $order->id)->sum('percent'), 2);
    }

    // ------------------------------------------------------------------ cancelling, and "work not done right"

    /**
     * Before work starts the advance is refunded; after it starts, it is not (the wood is bought, the cloth cut). If we cancel, it always is.
     *
     * @param  'buyer'|'staff'  $by
     */
    public function cancel(CustomOrder $order, User $actor, string $by, ?string $reason = null): CustomOrder
    {
        if (! in_array($order->status, ['requested', 'priced', 'confirmed', 'making', 'trial'], true)) {
            throw new StockException('It is too late to cancel this job.');
        }

        if ($by === 'buyer') {
            $this->mustBeBuyers($order, $actor);
        }

        $refund = $by === 'staff' || $order->started_at === null ? (float) $order->advance_paid : 0.0;

        $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by, 'cancel_reason' => $reason ? mb_substr(trim($reason), 0, 200) : null, 'refund_amount' => $refund]);
        $this->log($order, $actor, 'cancelled', $refund > 0 ? 'Cancelled. The advance is refunded.' : 'Cancelled. The advance is not refunded.');

        if ($by === 'buyer') {
            $this->tellStaff($order->buyer, 'cancelled a made-to-order job');
        } else {
            $this->messages->send($order->buyer, 'custom_cancelled', ['name' => $order->number()], '/custom/'.$order->public_token);
        }

        return $order->fresh();
    }

    /** "Work not done right": there is no return, so this is how a bad job is raised. */
    public function complain(CustomOrder $order, User $buyer, string $text): CustomOrder
    {
        $this->mustBeBuyers($order, $buyer);

        if (! in_array($order->status, ['done', 'collected'], true)) {
            throw new StockException('You can raise this once the work is ready.');
        }

        if ($order->complaint_status === 'open') {
            throw new StockException('We are already looking at this.');
        }

        if (mb_strlen(trim($text)) < 3) {
            throw new StockException('Write what is wrong.');
        }

        $order->update(['complaint' => mb_substr(trim($text), 0, 500), 'complaint_status' => 'open', 'complaint_note' => null]);
        $this->log($order, $buyer, 'complaint', 'Work not done right.');
        $this->tellStaff($buyer, 'says a made-to-order job was not done right');

        return $order->fresh();
    }

    /**
     * Our mistake: fix it or refund. The buyer's wrong measurements: the buyer's responsibility.
     *
     * @param  'fixed'|'refunded'|'buyer'  $outcome
     */
    public function resolveComplaint(CustomOrder $order, User $by, string $outcome, string $note, ?float $refund = null): CustomOrder
    {
        if ($order->complaint_status !== 'open') {
            throw new StockException('There is no complaint to answer.');
        }

        if (! in_array($outcome, ['fixed', 'refunded', 'buyer'], true) || mb_strlen(trim($note)) < 3) {
            throw new StockException('Choose what happens and write a note.');
        }

        if ($outcome === 'refunded' && ($refund === null || $refund <= 0 || $refund > (float) $order->price)) {
            throw new StockException('Check the refund amount.');
        }

        $order->update(['complaint_status' => $outcome, 'complaint_note' => mb_substr(trim($note), 0, 300), 'refund_amount' => $outcome === 'refunded' ? round((float) $order->refund_amount + $refund, 2) : $order->refund_amount]);
        $this->log($order, $by, 'complaint_'.$outcome, $note);
        $this->messages->send($order->buyer, 'custom_answered', ['name' => $order->number()], '/custom/'.$order->public_token);

        return $order->fresh();
    }

    /** Work that is done and not collected: remind after the set days, and again after as many. Returns how many were reminded. */
    public function remindPickup(): int
    {
        $days = $this->pickupDays();
        $n = 0;

        CustomOrder::where('status', 'done')->where('done_at', '<=', now()->subDays($days))->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', now()->subDays($days)))
            ->with('buyer')->each(function (CustomOrder $o) use (&$n) {
                $this->messages->send($o->buyer, 'custom_pickup', ['name' => $o->number()], '/custom/'.$o->public_token);
                $o->update(['reminded_at' => now()]);
                $n++;
            });

        return $n;
    }

    // ------------------------------------------------------------------ what each side sees

    /** The buyer sees the memo as a total: never what the wood cost or what the wages are. */
    public function forBuyer(CustomOrder $o): array
    {
        return [
            'token' => $o->public_token, 'number' => $o->number(), 'kind' => $o->kind, 'status' => $o->status, 'material' => $o->material_name, 'fabric_by' => $o->fabric_by, 'items' => $o->items,
            'details' => $o->details, 'note' => $o->note, 'urgent' => $o->urgent, 'has_photo' => $o->photo_path !== null, 'price' => $o->price !== null ? (float) $o->price : null,
            'urgent_fee' => (float) $o->urgent_fee, 'advance_percent' => $o->advance_percent, 'advance_due' => $o->advanceDue(), 'advance_paid' => (float) $o->advance_paid, 'days' => $o->days_needed,
            'due_on' => $o->due_on?->toDateString(), 'memo_note' => $o->memo_note, 'placed_at' => $o->created_at->toIso8601String(), 'cancel_reason' => $o->cancel_reason, 'refund' => (float) $o->refund_amount,
            'complaint' => $o->complaint, 'complaint_status' => $o->complaint_status, 'complaint_note' => $o->complaint_note,
            // How far, never who did it: the buyer does not see craftsmen's names.
            'progress' => (int) round($this->progress($o)), 'waiting' => $o->waiting_note !== null, 'late' => in_array($o->status, ['making', 'trial'], true) && $o->due_on !== null && $o->due_on->lt(today()),
            'can_confirm' => $o->status === 'priced',
            // Before work starts the advance comes back; after it starts it does not, and the page says so.
            'can_cancel' => in_array($o->status, ['requested', 'priced', 'confirmed'], true) ? 'refund' : (in_array($o->status, ['making', 'trial'], true) ? 'no_refund' : null),
            'can_complain' => in_array($o->status, ['done', 'collected'], true) && $o->complaint_status !== 'open',
            // What happens between us and the maker is ours: the buyer sees the steps of their own job, never who works on it.
            'history' => $o->events->reject(fn (CustomOrderEvent $e) => in_array($e->kind, ['assign', 'shift', 'share', 'wage_asked', 'wages_set', 'advance_given', 'progress', 'progress_fixed'], true))->values()->map(fn (CustomOrderEvent $e) => ['kind' => $e->kind, 'note' => $e->note, 'at' => $e->created_at->toIso8601String()])->values(),
        ];
    }

    // ------------------------------------------------------------------ helpers

    public function log(CustomOrder $order, ?User $actor, string $kind, ?string $note = null): void
    {
        CustomOrderEvent::create(['custom_order_id' => $order->id, 'actor_id' => $actor?->id, 'kind' => $kind, 'note' => $note]);
    }

    private function tellStaff(User $buyer, string $what): void
    {
        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'custom', 'body' => mb_substr(($buyer->business_name ?: $buyer->name).' '.__($what), 0, 290)]);
        }
    }

    private function mustBeBuyers(CustomOrder $order, User $user): void
    {
        if ($order->buyer_id !== $user->id) {
            throw new StockException('This is not your order.');
        }
    }

    private function material(int $id, string $kind): CustomMaterial
    {
        return CustomMaterial::where('id', $id)->where('kind', $kind)->where('is_active', true)->first()
            ?? throw new StockException($kind === 'wood' ? 'Choose a wood.' : 'Choose a fabric.');
    }

    /** @throws StockException */
    private function woodItems(array $items): array
    {
        $item = (array) ($items[0] ?? []);
        $unit = $item['unit'] ?? 'inch';

        if (! in_array($unit, (array) config('custom.units'), true)) {
            throw new StockException('Choose inch, foot or centimetre.');
        }

        return [['label' => mb_substr(trim((string) ($item['label'] ?? '')), 0, 80), 'unit' => $unit, 'values' => $this->profiles->clean('wood', (array) ($item['values'] ?? [])), 'quantity' => 1]];
    }

    /** @throws StockException */
    private function clothingItems(User $buyer, array $items): array
    {
        if ($items === [] || count($items) > 20) {
            throw new StockException('Add one to twenty people.');
        }

        return collect($items)->map(function ($raw) use ($buyer) {
            $raw = (array) $raw;
            $profile = ! empty($raw['profile_id']) ? MeasurementProfile::where('id', $raw['profile_id'])->where('user_id', $buyer->id)->first() : null;
            $kind = $profile?->kind ?? ($raw['kind'] ?? '');

            if (! in_array($kind, ['men', 'women'], true)) {
                throw new StockException('Choose men or women for each person.');
            }

            $unit = $profile?->unit ?? ($raw['unit'] ?? 'inch');
            if (! in_array($unit, (array) config('custom.units'), true)) {
                throw new StockException('Choose inch, foot or centimetre.');
            }

            if (mb_strlen(trim((string) ($raw['garment'] ?? ''))) < 2) {
                throw new StockException('Say what is to be made for each person.');
            }

            $quantity = max(1, min(20, (int) ($raw['quantity'] ?? 1)));

            // A saved profile is copied in with the date it was taken: the job keeps these numbers whatever happens to the profile after.
            return [
                'profile_id' => $profile?->id, 'label' => $profile?->name ?? mb_substr(trim((string) ($raw['label'] ?? '')), 0, 60), 'kind' => $kind, 'unit' => $unit, 'garment' => mb_substr(trim((string) $raw['garment']), 0, 80), 'quantity' => $quantity,
                'values' => $this->profiles->clean($kind, $profile?->values ?? (array) ($raw['values'] ?? [])), 'measured_on' => $profile?->measured_on->toDateString() ?? today()->toDateString(),
            ];
        })->values()->all();
    }

    private function details(string $kind, array $given): array
    {
        $keys = array_keys((array) config($kind === 'wood' ? 'custom.wood_options' : 'custom.clothing_options'));

        return collect($given)->only($keys)->map(fn ($v) => is_bool($v) ? $v : mb_substr(trim((string) $v), 0, 80))->filter(fn ($v) => $v !== '' && $v !== false)->all();
    }
}
