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

/** The shop window for services (item 613): see what can be booked, pick a time, ask for it, and keep the link to the booking. Open to everyone. */
class ServiceController extends Controller
{
    public function __construct(private Bookings $bookings) {}

    public function index(Request $request): Response
    {
        $area = trim((string) $request->query('area', ''));

        return Inertia::render('services/index', ['offers' => $this->bookings->open($area !== '' ? $area : null)->all(), 'area' => $area]);
    }

    public function show(Request $request, ServiceOffer $offer): Response
    {
        $offer->load('provider');
        abort_unless($offer->active && ! $offer->admin_hidden && $this->bookings->canGive($offer->provider), 404);

        return Inertia::render('services/show', [
            'offer' => $this->bookings->card($offer),
            'slots' => $this->bookings->freeSlots($offer)->map(fn (ServiceSlot $s) => ['id' => $s->id, 'at' => $s->starts_at->toIso8601String(), 'free' => $s->free()])->all(),
            'me' => $request->user() ? ['name' => $request->user()->name, 'phone' => $request->user()->phone] : null,
            'own' => $request->user()?->id === $offer->provider_id,
        ]);
    }

    public function book(Request $request, ServiceOffer $offer): RedirectResponse
    {
        $data = $request->validate([
            'slot_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        $slot = ServiceSlot::where('offer_id', $offer->id)->find($data['slot_id']);

        if ($slot === null) {
            return back()->withErrors(['book' => __('Pick one of the times.')]);
        }

        try {
            $booking = $this->bookings->book($slot, $request->user(), $data['name'], $data['phone'], $data['note'] ?? null);
        } catch (StockException $e) {
            return back()->withErrors(['book' => __($e->getMessage())])->withInput();
        }

        return to_route('services.booking', $booking->public_token)->with('status', __('Your request is sent. The provider will confirm it.'));
    }

    public function booking(string $token): Response
    {
        $b = ServiceBooking::where('public_token', $token)->with(['offer.provider', 'slot'])->firstOrFail();

        return Inertia::render('services/booking', [
            'booking' => [
                'token' => $b->public_token, 'status' => $b->status, 'name' => $b->name, 'when' => $b->slot->starts_at->toIso8601String(), 'price' => (float) $b->price, 'reason' => $b->reason, 'note' => $b->note,
                'service' => $this->bookings->card($b->offer), 'phone' => $b->status === 'confirmed' ? $b->offer->provider->phone : null,
                'can_cancel' => in_array($b->status, ServiceBooking::HOLDING, true) && $b->slot->starts_at->gte(now()->addHours($this->bookings->cancelHours())), 'cancel_hours' => $this->bookings->cancelHours(),
            ],
        ]);
    }

    public function cancel(string $token): RedirectResponse
    {
        $b = ServiceBooking::where('public_token', $token)->with(['offer.provider', 'slot'])->firstOrFail();

        try {
            $this->bookings->cancel($b);
        } catch (StockException $e) {
            return back()->withErrors(['cancel' => __($e->getMessage())]);
        }

        return back()->with('status', __('Your booking is cancelled.'));
    }
}
