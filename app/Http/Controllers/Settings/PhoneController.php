<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Security\PhoneVerifier;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A person proves their mobile number with a code. A verified number is what links their earlier guest orders to the account. */
class PhoneController extends Controller
{
    public function __construct(private PhoneVerifier $phones) {}

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/phone', [
            'phone' => $user->phone,
            'verified' => $user->phone_verified_at !== null,
            'pending' => $this->phones->pending($user),
            'linked' => $request->session()->get('orders_linked'),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        try {
            $this->phones->send($request->user(), $data['phone']);
        } catch (StockException $e) {
            return back()->withErrors(['phone' => __($e->getMessage())]);
        }

        return back()->with('status', __('We sent a code to your phone.'));
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        try {
            $done = $this->phones->verify($request->user(), $data['code']);
        } catch (StockException $e) {
            return back()->withErrors(['code' => __($e->getMessage())]);
        }

        return back()->with('status', __('Your number is verified.'))->with('orders_linked', $done['orders']);
    }
}
