<?php

namespace App\Http\Controllers;

use App\Models\RxInterest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The prescription marketplace (item 577), kept as a placeholder: what it will be, and a way to say you want it when it opens. */
class RxMarketController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('rx-market', [
            'me' => $user ? ['name' => $user->name, 'phone' => $user->phone, 'area' => $user->area] : null,
            'joined' => $user && $user->phone ? RxInterest::where('phone', $user->phone)->exists() : false,
        ]);
    }

    public function interest(Request $request): RedirectResponse
    {
        $request->merge(['phone' => $this->phone((string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'], 'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'], 'area' => ['nullable', 'string', 'max:80'],
        ], ['phone.regex' => __('Write a Bangladesh mobile number, like 01712345678.')]);

        RxInterest::firstOrCreate(['phone' => $data['phone']], ['user_id' => $request->user()?->id, 'name' => $data['name'] ?? null, 'area' => $data['area'] ?? null]);

        return back()->with('status', __('Thank you. We will tell you when it opens.'));
    }

    private function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }
}
