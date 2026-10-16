<?php

namespace App\Services\Sales;

use App\Models\DeliveryRating;
use App\Models\Invoice;
use App\Models\Notice;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Media\ImageCompressor;
use App\Services\Messages\Messenger;
use App\Services\Messages\SmsGateway;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Our own delivery people (spec ch 20). A staff member who sends an order by our own delivery picks who takes it; that person sees a list of
 * where to go and how much to collect, grouped by area, marks "Arrived" (the buyer is told), and finishes it with the buyer's code or, when the
 * buyer cannot give it, a photo of the parcel handed over. That proof is what makes a delivery real. We keep how much money each person holds
 * until they hand it in. After it, the buyer rates the delivery and the service, and a low rating comes to us at once.
 */
class Deliveries
{
    private const MAX_TRIES = 5;

    public function __construct(private Dispatching $dispatching, private InvoiceService $invoices, private Messenger $messages, private SmsGateway $sms, private ImageCompressor $images, private ActivityLogger $activity) {}

    // ------------------------------------------------------------------ who

    /** @return Collection<int, User> */
    public function people(): Collection
    {
        return User::where('account_type', 'staff')->with('role')->orderBy('name')->get()->filter(fn (User $u) => $u->hasPermission('deliveries'))->values();
    }

    // ------------------------------------------------------------------ sending it with one of our own

    /** @throws StockException */
    public function assign(Invoice $order, User $person, User $by): Invoice
    {
        if ($this->dispatching->advanceMissing($order)) {
            throw new StockException('This order must pay its delivery charge first. Mark it received before sending.');
        }

        if ($order->channel !== 'online' || $order->delivery_mode !== 'delivery' || $order->courier_id !== null) {
            throw new StockException('Our own delivery is only for an order we deliver ourselves.');
        }

        if ($order->delivered_at !== null) {
            throw new StockException('This order was already delivered.');
        }

        if ($order->delivery_person_id !== null && $order->arrived_at !== null) {
            throw new StockException('The delivery person is already at the door.');
        }

        if (! $person->hasPermission('deliveries')) {
            throw new StockException('Pick one of our delivery people.');
        }

        // Taking it out of the shop is what "sent" means.
        if ($order->sent_at === null) {
            $order = $this->dispatching->send($order, $by);
        }

        $first = $order->delivery_code === null;
        $order->update(['delivery_person_id' => $person->id, 'handled_by' => $by->id, 'delivery_code' => $order->delivery_code ?? (string) random_int(100000, 999999)]);

        // The code goes to the buyer's phone. Until a text service is connected it is also on the buyer's own order page.
        if ($first && ($phone = ($order->ship_to['phone'] ?? null))) {
            $this->sms->send((string) $phone, __('Your delivery code is {code}. Tell it to our delivery person when you get your order.', ['code' => $order->delivery_code], 'bn'));
        }

        $this->activity->record('order.assigned', $order, ['person' => $person->id], $by);

        return $order->fresh();
    }

    // ------------------------------------------------------------------ the delivery person's day

    /**
     * Where to go: the orders on this person's list, grouped by area so they go together.
     *
     * @return array<int, array{area: string, orders: array<int, array<string, mixed>>}>
     */
    public function list(User $person): array
    {
        return Invoice::where('delivery_person_id', $person->id)->whereNull('delivered_at')->whereNotIn('status', ['cancelled'])->orderBy('id')->get()
            ->map(fn (Invoice $o) => [
                'id' => $o->id, 'number' => $o->number ?? ('#'.$o->id), 'name' => $o->ship_to['name'] ?? null, 'phone' => $o->ship_to['phone'] ?? null,
                'area' => trim((string) ($o->ship_to['area'] ?? '')) ?: '—', 'address' => $o->ship_to['address'] ?? null, 'how_to_find' => $o->ship_to['how_to_find'] ?? null,
                'collect' => $this->due($o), 'arrived' => $o->arrived_at !== null, 'note' => $o->note,
            ])->groupBy('area')->map(fn (Collection $rows, string $area) => ['area' => $area, 'orders' => $rows->values()->all()])->sortKeys()->values()->all();
    }

    /** What the buyer still has to pay at the door: the total less what was paid ahead. */
    public function due(Invoice $o): float
    {
        $total = (float) $o->total > 0 ? (float) $o->total : (float) $o->subtotal + (float) $o->delivery_charge;

        return max(0.0, round($total - (float) $o->paid_amount, 2));
    }

    /** @throws StockException */
    public function arrived(Invoice $order, User $person): Invoice
    {
        $this->mine($order, $person);

        if ($order->arrived_at === null) {
            $order->update(['arrived_at' => now()]);
            $this->messages->orderArrived($order->fresh());
        }

        return $order->fresh();
    }

    /**
     * Finish it with the buyer's code. Wrong guesses are counted: after five the code is locked and we are told.
     *
     * @throws StockException
     */
    public function confirmWithCode(Invoice $order, User $person, string $code, ?float $collected = null): Invoice
    {
        $this->mine($order, $person);

        if ($order->delivery_code_tries >= self::MAX_TRIES) {
            throw new StockException('Too many wrong codes. Use a photo, or call the office.');
        }

        if ($order->delivery_code === null || ! hash_equals($order->delivery_code, trim($code))) {
            $order->increment('delivery_code_tries');

            if ($order->fresh()->delivery_code_tries >= self::MAX_TRIES) {
                $this->tellOffice($order, 'delivery_code_locked');
            }

            throw new StockException('That code is not right.');
        }

        return $this->deliver($order, $person, 'otp', $collected);
    }

    /** @throws StockException */
    public function confirmWithPhoto(Invoice $order, User $person, UploadedFile $photo, ?float $collected = null): Invoice
    {
        $this->mine($order, $person);
        $order->update(['delivery_photo_path' => $this->images->store($photo, 'delivery')]);

        return $this->deliver($order, $person, 'photo', $collected);
    }

    // ------------------------------------------------------------------ the money they hold

    /** @return array<int, array{id: int, name: string, holding: float, orders: int}> */
    public function holdings(): array
    {
        $rows = Invoice::whereNotNull('delivery_person_id')->whereNotNull('cash_collected')->whereNull('cash_handed_at')->get(['delivery_person_id', 'cash_collected'])->groupBy('delivery_person_id');

        return $this->people()->map(fn (User $p) => [
            'id' => $p->id, 'name' => $p->name, 'holding' => round((float) ($rows->get($p->id)?->sum('cash_collected') ?? 0), 2), 'orders' => (int) ($rows->get($p->id)?->count() ?? 0),
        ])->all();
    }

    public function holding(User $person): float
    {
        return round((float) Invoice::where('delivery_person_id', $person->id)->whereNotNull('cash_collected')->whereNull('cash_handed_at')->sum('cash_collected'), 2);
    }

    /** They gave us the money: everything they held is settled. Returns how much. */
    public function handIn(User $person, User $by): float
    {
        return DB::transaction(function () use ($person, $by) {
            $amount = $this->holding($person);
            Invoice::where('delivery_person_id', $person->id)->whereNotNull('cash_collected')->whereNull('cash_handed_at')->update(['cash_handed_at' => now()]);
            $this->activity->record('delivery.cash_handed', $person, ['amount' => $amount], $by);

            return $amount;
        });
    }

    // ------------------------------------------------------------------ the buyer's rating

    public function canRate(Invoice $order): bool
    {
        return $order->delivered_at !== null && $order->channel === 'online' && ! DeliveryRating::where('invoice_id', $order->id)->exists();
    }

    /** @throws StockException */
    public function rate(Invoice $order, int $delivery, int $service, ?string $comment): DeliveryRating
    {
        if ($order->delivered_at === null) {
            throw new StockException('You can rate it after it has arrived.');
        }

        if (DeliveryRating::where('invoice_id', $order->id)->exists()) {
            throw new StockException('You already rated this order.');
        }

        if ($delivery < 1 || $delivery > 5 || $service < 1 || $service > 5) {
            throw new StockException('Pick from one to five stars.');
        }

        $rating = DeliveryRating::create([
            'invoice_id' => $order->id, 'delivery_person_id' => $order->delivery_person_id, 'staff_id' => $order->handled_by ?? $order->finalised_by,
            'delivery_stars' => $delivery, 'service_stars' => $service, 'comment' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 500) : null,
        ]);

        // A low rating comes to us at once (spec ch 20).
        if ($delivery <= 2 || $service <= 2) {
            $this->tellOffice($order, 'delivery_low_rating');
        }

        return $rating;
    }

    /**
     * Who is doing well: the average for each delivery person and each staff member, and the worst recent ratings.
     *
     * @return array{people: array<int, array<string, mixed>>, staff: array<int, array<string, mixed>>, low: array<int, array<string, mixed>>}
     */
    public function averages(): array
    {
        $names = fn ($ids) => User::whereIn('id', $ids)->pluck('name', 'id');
        $by = function (string $column, string $stars) use ($names) {
            $rows = DeliveryRating::whereNotNull($column)->selectRaw("$column as who, count(*) as n, avg($stars) as a, min($stars) as worst")->groupBy($column)->get();
            $labels = $names($rows->pluck('who'));

            return $rows->map(fn ($r) => ['id' => (int) $r->who, 'name' => $labels[$r->who] ?? '—', 'average' => round((float) $r->a, 1), 'count' => (int) $r->n, 'worst' => (int) $r->worst])->sortBy('average')->values()->all();
        };

        $low = DeliveryRating::where(fn ($q) => $q->where('delivery_stars', '<=', 2)->orWhere('service_stars', '<=', 2))->latest('id')->limit(20)->get();
        $labels = $names($low->pluck('delivery_person_id')->merge($low->pluck('staff_id'))->filter()->unique());

        return [
            'people' => $by('delivery_person_id', 'delivery_stars'), 'staff' => $by('staff_id', 'service_stars'),
            'low' => $low->map(fn (DeliveryRating $r) => [
                'id' => $r->id, 'person' => $labels[$r->delivery_person_id] ?? null, 'staff' => $labels[$r->staff_id] ?? null, 'delivery' => $r->delivery_stars, 'service' => $r->service_stars,
                'comment' => $r->comment, 'at' => $r->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** @throws StockException */
    private function deliver(Invoice $order, User $person, string $proof, ?float $collected): Invoice
    {
        if ($order->delivered_at !== null) {
            throw new StockException('This order was already delivered.');
        }

        $due = $this->due($order);
        $order->update(['cash_collected' => $collected !== null ? max(0, round($collected, 2)) : $due, 'arrived_at' => $order->arrived_at ?? now()]);
        $done = $this->dispatching->received($order->fresh(), $proof);

        // Proof of delivery turns a draft sale into a complete one (spec ch 06, 20): a checked order not yet made into a memo becomes one now.
        if ($done->status === 'verified') {
            try {
                $this->invoices->finalise($done, $person, 'cash', (float) ($done->total > 0 ? $done->total : $done->subtotal + $done->delivery_charge));
            } catch (StockException $e) {
                $this->activity->record('delivery.not_finalised', $done, ['why' => $e->getMessage()], $person);
            }
        }

        $this->activity->record('order.delivered', $done, ['proof' => $proof, 'collected' => $order->fresh()->cash_collected], $person);

        return $done->fresh();
    }

    /** @throws StockException */
    private function mine(Invoice $order, User $person): void
    {
        if ($order->delivery_person_id !== $person->id) {
            throw new StockException('This is not on your list.');
        }

        if ($order->delivered_at !== null) {
            throw new StockException('This order was already delivered.');
        }
    }

    private function tellOffice(Invoice $order, string $kind): void
    {
        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => $kind, 'body' => mb_substr((string) ($order->number ?? '#'.$order->id), 0, 290)]);
        }
    }
}
