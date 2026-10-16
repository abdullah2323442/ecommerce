<?php

namespace App\Services\Couriers;

use App\Models\Invoice;
use App\Models\Notice;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Integrations\Integrations;
use App\Services\Sales\Deliveries;
use App\Services\Sales\Dispatching;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\DB;

/**
 * Booking a parcel with a courier company and following it (spec ch 20). A courier on the shop's list can be tied to a company that is connected in
 * Admin > Connections; then, for an order that goes by that courier, staff press one button and the company's number is saved on the order, the order
 * is marked sent with that number as its tracking note, and the buyer is told. Care taken:
 *  - a parcel is booked once only (the order is locked while the company is asked, and a saved number blocks a second booking, because a second booking
 *    is a second charge);
 *  - real parcels are booked only when the connection is in live mode;
 *  - the amount to collect is what the buyer still owes, never the full total after an advance;
 *  - progress comes back by the company's messages and by asking now and then; a delivered parcel counts as delivered unless the buyer said it did not come.
 */
class CourierBooking
{
    /** The company's word for where the parcel is → what we show the buyer. */
    public const STATUS_LABELS = [
        'in_review' => 'With the courier', 'pending' => 'With the courier', 'hold' => 'On hold', 'unknown' => 'With the courier',
        'delivered' => 'Delivered', 'partial_delivered' => 'Part delivered', 'cancelled' => 'Returned',
        'delivered_approval_pending' => 'With the courier', 'partial_delivered_approval_pending' => 'With the courier', 'cancelled_approval_pending' => 'With the courier',
        'unknown_approval_pending' => 'With the courier',
    ];

    /** @var array<string, class-string<CourierProvider>> */
    private const PROVIDERS = ['steadfast' => SteadfastProvider::class, 'pathao' => PathaoProvider::class];

    public function __construct(private Integrations $integrations, private Dispatching $dispatching, private Deliveries $deliveries, private ActivityLogger $activity) {}

    /** The companies that can be tied to a courier: [key, name, connected]. @return array<int, array{key: string, name: string, connected: bool}> */
    public function providers(): array
    {
        return collect(self::PROVIDERS)->map(fn ($class, $key) => ['key' => $key, 'name' => (string) config("integrations.providers.courier:{$key}.name"), 'connected' => $this->integrations->active("courier:{$key}") !== null])->values()->all();
    }

    public function provider(string $key): CourierProvider
    {
        return app(self::PROVIDERS[$key] ?? throw new StockException('Unknown courier company.'));
    }

    /** What the order page shows staff: can it be booked, and if not, why not. @return array<string, mixed>|null */
    public function panel(Invoice $order): ?array
    {
        $provider = $order->courier?->provider;

        if ($order->channel !== 'online' || ! $provider || ! isset(self::PROVIDERS[$provider])) {
            return null;
        }

        return [
            'provider' => $provider, 'name' => (string) config("integrations.providers.courier:{$provider}.name"), 'booked' => $order->courier_consignment !== null,
            'consignment' => $order->courier_consignment, 'status' => $order->courier_status, 'status_label' => $order->courier_status ? (self::STATUS_LABELS[$order->courier_status] ?? 'With the courier') : null,
            'status_at' => $order->courier_status_at?->toIso8601String(), 'tracking' => $order->tracking,
            'blocked' => $order->courier_consignment === null ? $this->whyNot($order) : null, 'collect' => $this->deliveries->due($order),
            // Pathao wants its own city and zone; staff see the guess and can change it.
            'places' => $provider === 'pathao',
        ];
    }

    /**
     * Book it with the company.
     *
     * @throws StockException
     */
    public function book(Invoice $order, User $by, array $place = []): Invoice
    {
        $done = DB::transaction(function () use ($order, $by, $place) {
            // Locked, so two staff pressing the button together make one booking.
            $order = Invoice::whereKey($order->id)->lockForUpdate()->with('courier')->firstOrFail();

            if ($order->courier_consignment !== null) {
                throw new StockException('This parcel is already booked.');
            }

            if ($why = $this->whyNot($order)) {
                throw new StockException($why);
            }

            $provider = $this->provider($order->courier->provider);
            $ship = (array) $order->ship_to;

            try {
                $r = $provider->book([
                    'invoice' => 'ORD'.$order->id, 'name' => (string) ($ship['name'] ?? ''), 'phone' => $this->phone((string) ($ship['phone'] ?? '')),
                    'address' => $this->address($ship), 'cod' => $this->deliveries->due($order),
                    'district' => (string) ($ship['district'] ?? ''), 'area' => (string) ($ship['area'] ?? ''), 'weight' => $this->weightKg($order), 'note' => (string) ($ship['how_to_find'] ?? ''),
                    'city_id' => (int) ($place['city_id'] ?? 0), 'zone_id' => (int) ($place['zone_id'] ?? 0),
                ]);
            } catch (CourierException $e) {
                throw new StockException($e->getMessage());
            }

            $order->update(['courier_consignment' => $r['consignment_id'], 'courier_status' => $r['status'], 'courier_status_at' => now(), 'courier_booked_at' => now(), 'tracking' => $r['tracking_code'] ?? $order->tracking]);
            $this->activity->record('order.booked', $order, ['company' => $order->courier->provider, 'consignment' => $r['consignment_id']], $by);

            return $order;
        });

        // Taking it to the courier is what "sent" means: the buyer is told, with the company's number.
        if ($done->sent_at === null) {
            $done = $this->dispatching->send($done, $by, 0, $done->tracking);
        }

        return $done->fresh();
    }

    /** Ask the company where it is now. @throws StockException */
    public function refresh(Invoice $order): Invoice
    {
        if ($order->courier_consignment === null || ! $order->courier?->provider) {
            throw new StockException('This parcel is not booked.');
        }

        try {
            $status = $this->provider($order->courier->provider)->status($order->courier_consignment);
        } catch (CourierException $e) {
            throw new StockException($e->getMessage());
        }

        return $this->apply($order, $status);
    }

    /** A new word from the company (their message, or our asking). */
    public function apply(Invoice $order, string $status): Invoice
    {
        $status = mb_substr($status, 0, 30);

        if ($order->courier_status === $status) {
            return $order;
        }

        $order->update(['courier_status' => $status, 'courier_status_at' => now()]);

        // Delivered by the company counts as delivered, unless the buyer said it did not come (then the office looks into it).
        if ($status === 'delivered' && $order->sent_at !== null && $order->delivered_at === null && $order->not_received_at === null) {
            $this->dispatching->received($order->fresh(), 'courier');
        }

        // A parcel that came back, part came, or is stuck: the office should know.
        if (in_array($status, ['cancelled', 'partial_delivered', 'hold'], true)) {
            foreach (User::withPermission('orders') as $staff) {
                Notice::create(['user_id' => $staff->id, 'kind' => 'courier_problem', 'body' => mb_substr((string) ($order->number ?? 'ORD'.$order->id), 0, 100)]);
            }
        }

        return $order->fresh();
    }

    /** Ask about every parcel still on its way. Returns how many changed. */
    public function sweep(): int
    {
        $changed = 0;

        Invoice::whereNotNull('courier_consignment')->whereNull('delivered_at')->whereNull('not_received_at')->where('courier_booked_at', '>', now()->subDays(30))->with('courier')->limit(200)->get()
            ->each(function (Invoice $o) use (&$changed) {
                try {
                    $before = $o->courier_status;
                    $this->refresh($o);
                    $changed += $o->fresh()->courier_status !== $before ? 1 : 0;
                } catch (StockException) {
                    // That company is not reachable now: try again next time.
                }
            });

        return $changed;
    }

    // ------------------------------------------------------------------ helpers

    /** Why this order cannot be booked now, or null. */
    private function whyNot(Invoice $order): ?string
    {
        $provider = $order->courier?->provider;
        $on = $provider ? $this->integrations->active("courier:{$provider}") : null;

        return match (true) {
            $order->channel !== 'online' || ! in_array($order->delivery_mode, ['send', 'delivery'], true) => 'Only an order that goes by courier can be booked.',
            ! in_array($order->status, ['verified', 'final'], true) => 'Check the order first.',
            $order->lines()->whereHas('medicine', fn ($q) => $q->where('is_cold_chain', true))->where('quantity', '>', 0)->exists() => 'This order has a medicine that must stay cold. It cannot go by courier.',
            $order->delivered_at !== null => 'This order was already delivered.',
            ! $provider => 'This courier is not tied to a courier company.',
            ! $on => 'The courier company is not switched on in Connections.',
            ($on['mode'] ?? 'test') !== 'live' => 'The courier company is in testing mode. Switch it to live to book real parcels.',
            default => null,
        };
    }

    /** What the parcel weighs, from the weights of the products on the order (half a kilo at least, as the companies charge). */
    private function weightKg(Invoice $order): float
    {
        $g = $order->lines()->with('medicine')->where('quantity', '>', 0)->get()->sum(fn ($l) => $l->medicine ? (int) $l->medicine->weight_g * (float) $l->quantity * \App\Services\Sales\InvoiceService::fraction($l->medicine, (string) $l->unit) : 0);

        return max(0.5, round($g / 1000, 1));
    }

    /** 01XXXXXXXXX, as the companies want it. */
    private function phone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($d, '880') ? '0'.substr($d, 3) : $d;
    }

    private function address(array $ship): string
    {
        return mb_substr(trim(implode(', ', array_filter([$ship['address'] ?? null, $ship['how_to_find'] ?? null, $ship['area'] ?? null, $ship['district'] ?? null]))), 0, 250);
    }
}
