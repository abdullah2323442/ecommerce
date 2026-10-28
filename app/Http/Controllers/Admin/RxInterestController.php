<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RxInterest;
use App\Models\RxOffer;
use App\Models\RxRequest;
use Inertia\Inertia;
use Inertia\Response;

/** How many people want the prescription marketplace (item 577), before it is built. */
class RxInterestController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/rx-market', [
            'total' => RxInterest::count(),
            'people' => RxInterest::latest('id')->limit(100)->get()->map(fn (RxInterest $i) => ['id' => $i->id, 'name' => $i->name, 'phone' => $i->phone, 'area' => $i->area, 'at' => $i->created_at->toIso8601String()])->all(),
            'ready' => ['requests' => RxRequest::count(), 'offers' => RxOffer::count()],
        ]);
    }
}
