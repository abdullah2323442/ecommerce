<?php

namespace App\Http\Controllers;

use App\Models\ServiceBooking;
use App\Models\ServiceOffer;
use App\Models\ServiceSlot;
use App\Services\Booking\Bookings;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A provider's own services, times and bookings (item 613). Only a selling account in good standing can open it. */
class SellServicesController extends Controller
{
    public function __construct(private Bookings $bookings) {}

    public function index(Request $request): Response
    {
        $me = $this->provider($request);
        $offers = ServiceOffer::where('provider_id', $me->id)->with(['slots' => fn ($q) => $q->where('starts_at', '>=', now())->orderBy('starts_at')->limit(40)])->orderByDesc('id')->get();

        return Inertia::render('market/sell-services', [
            'offers' => $offers->map(fn (ServiceOffer $o) => [
                'id' => $o->id, 'title' => $o->title, 'description' => $o->description, 'minutes' => $o->minutes, 'price' => (float) $o->price, 'place' => $o->place, 'area' => $o->area, 'active' => $o->active, 'hidden' => $o->admin_hidden,
                'slots' => $o->slots->map(fn (ServiceSlot $s) => ['id' => $s->id, 'at' => $s->starts_at->toIso8601String(), 'capacity' => $s->capacity, 'booked' => $s->booked])->all(),
            ])->all(),
            'bookings' => ServiceBooking::whereHas('offer', fn ($q) => $q->where('provider_id', $me->id))->with(['offer:id,title', 'slot:id,starts_at'])
                ->where(fn ($w) => $w->whereIn('status', ['pending', 'confirmed'])->orWhere(fn ($d) => $d->where('status', 'done')->where('updated_at', '>=', now()->subDays(14))))
                ->get()->sortBy(fn (ServiceBooking $b) => $b->slot->starts_at)->values()
                ->map(fn (ServiceBooking $b) => [
                    'id' => $b->id, 'service' => $b->offer->title, 'status' => $b->status, 'name' => $b->name, 'phone' => $b->phone, 'note' => $b->note, 'price' => (float) $b->price, 'when' => $b->slot->starts_at->toIso8601String(), 'due' => $b->slot->starts_at->isPast(),
                ])->all(),
            'confirmHours' => $this->bookings->confirmHours(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, ServiceOffer $offer): RedirectResponse
    {
        return $this->save($request, $offer);
    }

    private function save(Request $request, ?ServiceOffer $offer): RedirectResponse
    {
        $me = $this->provider($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:2000'], 'minutes' => ['required', 'integer', 'min:10', 'max:600'], 'price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'place' => ['required', 'string', 'max:12'], 'area' => ['nullable', 'string', 'max:80'], 'active' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->bookings->saveOffer($me, $data, $offer);
        } catch (StockException $e) {
            return back()->withErrors(['service' => __($e->getMessage())])->withInput();
        }

        return back()->with('status', __('Saved.'));
    }

    public function addSlots(Request $request, ServiceOffer $offer): RedirectResponse
    {
        $me = $this->provider($request);
        $data = $request->validate(['starts_at' => ['required', 'string', 'max:30'], 'capacity' => ['required', 'integer', 'min:1', 'max:100'], 'days' => ['nullable', 'integer', 'min:1', 'max:60']]);

        try {
            $n = $this->bookings->addSlots($me, $offer, $data['starts_at'], (int) $data['capacity'], (int) ($data['days'] ?? 1));
        } catch (StockException $e) {
            return back()->withErrors(['slots' => __($e->getMessage())]);
        }

        return back()->with('status', $n > 0 ? __('{n} times added.', ['n' => $n]) : __('Those times were already there.'));
    }

    public function removeSlot(Request $request, ServiceSlot $slot): RedirectResponse
    {
        try {
            $this->bookings->removeSlot($this->provider($request), $slot);
        } catch (StockException $e) {
            return back()->withErrors(['slots' => __($e->getMessage())]);
        }

        return back()->with('status', __('The time is removed.'));
    }

    public function confirm(Request $request, ServiceBooking $booking): RedirectResponse
    {
        return $this->answer(fn () => $this->bookings->confirm($booking, $this->provider($request)), __('Confirmed. The buyer is told.'));
    }

    public function decline(Request $request, ServiceBooking $booking): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return $this->answer(fn () => $this->bookings->decline($booking, $this->provider($request), $data['reason'] ?? null), __('Declined. The buyer is told.'));
    }

    public function done(Request $request, ServiceBooking $booking): RedirectResponse
    {
        return $this->answer(fn () => $this->bookings->markDone($booking, $this->provider($request)), __('Marked done.'));
    }

    private function answer(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['booking' => __($e->getMessage())]);
        }

        return back()->with('status', $done);
    }

    private function provider(Request $request)
    {
        $user = $request->user();
        abort_unless($this->bookings->canGive($user), 403);

        return $user;
    }
}
