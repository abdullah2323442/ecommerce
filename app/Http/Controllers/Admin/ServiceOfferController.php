<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use App\Models\ServiceOffer;
use App\Services\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's look at the services people give, with a switch to hide one that should not be shown (item 613). */
class ServiceOfferController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/services', [
            'offers' => ServiceOffer::with('provider:id,name,business_name,phone')->withCount(['bookings as open_bookings' => fn ($q) => $q->whereIn('status', ServiceBooking::HOLDING)])->latest('id')->limit(200)->get()
                ->map(fn (ServiceOffer $o) => [
                    'id' => $o->id, 'title' => $o->title, 'provider' => $o->provider->business_name ?: $o->provider->name, 'phone' => $o->provider->phone, 'price' => (float) $o->price, 'place' => $o->place,
                    'active' => $o->active, 'hidden' => $o->admin_hidden, 'open' => (int) $o->open_bookings,
                ])->all(),
        ]);
    }

    public function hide(Request $request, ServiceOffer $offer): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $offer->update(['admin_hidden' => (bool) $data['hidden']]);
        $this->activity->record($data['hidden'] ? 'service.hidden' : 'service.shown', $offer, [], $request->user());

        return back()->with('status', $data['hidden'] ? __('Hidden from buyers.') : __('Shown to buyers again.'));
    }
}
