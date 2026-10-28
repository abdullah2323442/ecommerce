<?php

namespace App\Services\Booking;

use App\Models\ServiceBooking;
use App\Models\ServiceOffer;
use App\Models\ServiceSlot;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Market\Marketplace;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service and coaching booking (item 613): someone who gives a service (a lesson, a repair, a visit) lists it with the times they can do it, and a buyer books a
 * time. A booking holds one place from the moment it is asked for; the provider confirms or declines it, and a request nobody answers in time is declined by
 * itself so the place is not lost. The buyer may cancel until a set number of hours before the start. Payment is made to the provider at the service, so nothing
 * here moves money. Everyone who has an account is told by message; a visitor without one keeps the link to their booking.
 */
class Bookings
{
    public const PLACES = ['provider', 'online', 'home'];

    public function __construct(private Marketplace $market, private Messenger $messages, private ActivityLogger $activity) {}

    // ------------------------------------------------------------------ settings

    /** Hours before the start after which a buyer can no longer cancel by themselves. */
    public function cancelHours(): int
    {
        return max(0, (int) Setting::read('services.cancel_hours', 12));
    }

    /** Hours a provider has to answer before the request is declined by itself. */
    public function confirmHours(): int
    {
        return max(1, (int) Setting::read('services.confirm_hours', 24));
    }

    // ------------------------------------------------------------------ the provider's side

    /** Only a selling account (a trade account, in good standing) can give a service. */
    public function canGive(User $user): bool
    {
        return $this->market->canSell($user);
    }

    /**
     * @param  array<string, mixed>  $in  title, description, minutes, price, place, area, active
     *
     * @throws StockException
     */
    public function saveOffer(User $provider, array $in, ?ServiceOffer $offer = null): ServiceOffer
    {
        abort_unless($this->canGive($provider), 403);

        if ($offer !== null) {
            abort_unless($offer->provider_id === $provider->id, 403);
        }

        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
        $minutes = (int) ($in['minutes'] ?? 60);
        $price = round((float) ($in['price'] ?? 0), 2);
        $place = (string) ($in['place'] ?? 'provider');

        if (mb_strlen($title) < 3) {
            throw new StockException('Give the service a name.');
        }

        if ($minutes < 10 || $minutes > 600) {
            throw new StockException('A service takes between 10 minutes and 10 hours.');
        }

        if ($price < 0 || $price > 10000000) {
            throw new StockException('Write the price, or 0 if people should ask you.');
        }

        if (! in_array($place, self::PLACES, true)) {
            throw new StockException('Say where it happens: at your place, online, or at their home.');
        }

        $data = [
            'title' => $title, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000) ?: null, 'minutes' => $minutes, 'price' => $price, 'place' => $place,
            'area' => mb_substr(trim((string) ($in['area'] ?? '')), 0, 80) ?: null, 'active' => (bool) ($in['active'] ?? true),
        ];

        $offer = $offer ? tap($offer)->update($data) : ServiceOffer::create($data + ['provider_id' => $provider->id]);
        $this->activity->record('service.saved', $offer, ['title' => $title], $provider);

        return $offer;
    }

    /**
     * Add one time, or the same time on several days running.
     *
     * @throws StockException
     */
    public function addSlots(User $provider, ServiceOffer $offer, string $startsAt, int $capacity, int $days = 1): int
    {
        abort_unless($offer->provider_id === $provider->id && $this->canGive($provider), 403);

        try {
            $first = Carbon::parse($startsAt);
        } catch (\Throwable) {
            throw new StockException('Write the day and time.');
        }

        if ($capacity < 1 || $capacity > 100) {
            throw new StockException('A time holds between 1 and 100 places.');
        }

        if ($days < 1 || $days > 60) {
            throw new StockException('Repeat for 1 to 60 days.');
        }

        if ($first->lt(now()->addMinutes(30))) {
            throw new StockException('Pick a time that is still to come.');
        }

        $made = 0;

        for ($i = 0; $i < $days; $i++) {
            $at = $first->copy()->addDays($i);

            if (! ServiceSlot::where('offer_id', $offer->id)->where('starts_at', $at)->exists()) {
                ServiceSlot::create(['offer_id' => $offer->id, 'starts_at' => $at, 'capacity' => $capacity, 'booked' => 0]);
                $made++;
            }
        }

        return $made;
    }

    /** @throws StockException */
    public function removeSlot(User $provider, ServiceSlot $slot): void
    {
        abort_unless($slot->offer->provider_id === $provider->id, 403);

        if (ServiceBooking::where('slot_id', $slot->id)->whereIn('status', ServiceBooking::HOLDING)->exists()) {
            throw new StockException('Someone has booked this time. Decline or cancel their booking first.');
        }

        $slot->delete();
    }

    public function confirm(ServiceBooking $booking, User $provider): ServiceBooking
    {
        $this->mustBeProviders($booking, $provider);

        return $this->decide($booking, 'confirmed', null, 'confirmed', $provider, ['pending']);
    }

    public function decline(ServiceBooking $booking, User $provider, ?string $reason): ServiceBooking
    {
        $this->mustBeProviders($booking, $provider);

        return $this->decide($booking, 'declined', $reason, 'declined', $provider, ['pending', 'confirmed']);
    }

    /** @throws StockException */
    public function markDone(ServiceBooking $booking, User $provider): ServiceBooking
    {
        $this->mustBeProviders($booking, $provider);

        if ($booking->status !== 'confirmed') {
            throw new StockException('Only a confirmed booking can be marked done.');
        }

        if ($booking->slot->starts_at->isFuture()) {
            throw new StockException('It cannot be done before its time.');
        }

        $booking->update(['status' => 'done', 'decided_at' => now()]);

        return $booking;
    }

    // ------------------------------------------------------------------ the buyer's side

    /**
     * Book one time. The place is held at once; the provider answers.
     *
     * @throws StockException
     */
    public function book(ServiceSlot $slot, ?User $buyer, string $name, string $phone, ?string $note): ServiceBooking
    {
        $name = mb_substr(trim($name), 0, 120);
        $phone = $this->phone($phone);

        if (mb_strlen($name) < 2) {
            throw new StockException('Write your name.');
        }

        if (! preg_match('/^01[3-9]\d{8}$/', $phone)) {
            throw new StockException('Write a Bangladesh mobile number, like 01712345678.');
        }

        $booking = DB::transaction(function () use ($slot, $buyer, $name, $phone, $note) {
            $slot = ServiceSlot::whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $offer = $slot->offer;

            if (! $offer->active || $offer->admin_hidden || ! $this->canGive($offer->provider)) {
                throw new StockException('This service is not open for booking.');
            }

            if ($buyer && $buyer->id === $offer->provider_id) {
                throw new StockException('You cannot book your own service.');
            }

            if ($slot->starts_at->lt(now()->addMinutes(30))) {
                throw new StockException('This time has passed or is too close. Pick another.');
            }

            if ($slot->free() < 1) {
                throw new StockException('This time is full. Pick another.');
            }

            if (ServiceBooking::where('slot_id', $slot->id)->where('phone', $phone)->whereIn('status', ServiceBooking::HOLDING)->exists()) {
                throw new StockException('You already asked for this time.');
            }

            $slot->increment('booked');

            return ServiceBooking::create([
                'public_token' => Str::random(40), 'offer_id' => $offer->id, 'slot_id' => $slot->id, 'buyer_id' => $buyer?->id, 'name' => $name, 'phone' => $phone,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null, 'price' => $offer->price, 'status' => 'pending',
            ]);
        });

        $this->messages->send($booking->offer->provider, 'booking_new', ['name' => $booking->offer->title, 'when' => $booking->slot->starts_at->format('j M, g:i A')], '/wholesale/sell/services');

        return $booking;
    }

    /**
     * A buyer cancels (the one who booked, or anyone with the link) until the set hours before the start.
     *
     * @throws StockException
     */
    public function cancel(ServiceBooking $booking): ServiceBooking
    {
        if (! in_array($booking->status, ServiceBooking::HOLDING, true)) {
            throw new StockException('This booking is already finished.');
        }

        if ($booking->slot->starts_at->lt(now()->addHours($this->cancelHours()))) {
            throw new StockException(__('It is too late to cancel here. Call the provider, or cancel at least {n} hours before.', ['n' => $this->cancelHours()]));
        }

        DB::transaction(function () use ($booking) {
            $this->release($booking);
            $booking->update(['status' => 'cancelled', 'decided_at' => now()]);
        });

        $this->messages->send($booking->offer->provider, 'booking_answer', ['name' => $booking->offer->title, 'state' => 'cancelled', 'when' => $booking->slot->starts_at->format('j M, g:i A')], '/wholesale/sell/services');

        return $booking;
    }

    // ------------------------------------------------------------------ the shop window

    /**
     * What a visitor can book, with the next free time of each.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function open(?string $area = null): Collection
    {
        $offers = ServiceOffer::where('active', true)->where('admin_hidden', false)->with('provider')
            ->when($area, fn ($q) => $q->where(fn ($w) => $w->where('area', 'like', '%'.addcslashes($area, '%_\\').'%')->orWhere('place', 'online')))
            ->orderByDesc('id')->get()->filter(fn (ServiceOffer $o) => $this->canGive($o->provider));

        return $offers->map(function (ServiceOffer $o) {
            $next = $this->freeSlots($o)->first();

            return $next ? $this->card($o) + ['next' => $next->starts_at->toIso8601String()] : null;
        })->filter()->values();
    }

    /** @return Collection<int, ServiceSlot> the future times that still have a place, soonest first */
    public function freeSlots(ServiceOffer $offer): Collection
    {
        return $offer->slots()->where('starts_at', '>=', now()->addMinutes(30))->orderBy('starts_at')->limit(60)->get()->filter(fn (ServiceSlot $s) => $s->free() > 0)->values();
    }

    /** @return array<string, mixed> */
    public function card(ServiceOffer $o): array
    {
        return [
            'id' => $o->id, 'title' => $o->title, 'description' => $o->description, 'minutes' => $o->minutes, 'price' => (float) $o->price, 'place' => $o->place, 'area' => $o->area,
            'provider' => $o->provider->business_name ?: $o->provider->name,
        ];
    }

    // ------------------------------------------------------------------ the sweeps

    /** A request nobody answered in time is declined, so the place goes back. @return int how many */
    public function expireUnanswered(): int
    {
        $n = 0;

        foreach (ServiceBooking::where('status', 'pending')->where('created_at', '<=', now()->subHours($this->confirmHours()))->with(['offer', 'slot'])->get() as $b) {
            $this->decide($b, 'declined', 'No answer in time', 'declined', null, ['pending']);
            $n++;
        }

        return $n;
    }

    /** A day before, a confirmed booking is remembered to the buyer. @return int how many */
    public function sendReminders(): int
    {
        $n = 0;
        $due = ServiceBooking::where('status', 'confirmed')->whereNull('reminded_at')->whereNotNull('buyer_id')
            ->whereHas('slot', fn ($q) => $q->whereBetween('starts_at', [now()->addMinutes(30), now()->addHours(24)]))->with(['offer', 'slot', 'buyer'])->get();

        foreach ($due as $b) {
            $b->update(['reminded_at' => now()]);
            $this->messages->send($b->buyer, 'booking_answer', ['name' => $b->offer->title, 'state' => 'reminder', 'when' => $b->slot->starts_at->format('j M, g:i A')], '/services/booking/'.$b->public_token);
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<string>  $from
     *
     * @throws StockException
     */
    private function decide(ServiceBooking $booking, string $to, ?string $reason, string $state, ?User $by, array $from): ServiceBooking
    {
        if (! in_array($booking->status, $from, true)) {
            throw new StockException('This booking was already answered.');
        }

        DB::transaction(function () use ($booking, $to) {
            if ($to === 'declined') {
                $this->release($booking);
            }

            $booking->update(['status' => $to, 'decided_at' => now()]);
        });

        if ($reason !== null && trim($reason) !== '') {
            $booking->update(['reason' => mb_substr(trim($reason), 0, 200)]);
        }

        if ($booking->buyer_id !== null) {
            $this->messages->send($booking->buyer, 'booking_answer', ['name' => $booking->offer->title, 'state' => $state, 'when' => $booking->slot->starts_at->format('j M, g:i A'), 'note' => (string) $booking->reason], '/services/booking/'.$booking->public_token);
        }

        return $booking;
    }

    /** Give the place back to its time. */
    private function release(ServiceBooking $booking): void
    {
        ServiceSlot::whereKey($booking->slot_id)->where('booked', '>', 0)->decrement('booked');
    }

    private function mustBeProviders(ServiceBooking $booking, User $provider): void
    {
        abort_unless($booking->offer->provider_id === $provider->id, 403);
    }

    private function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }
}
