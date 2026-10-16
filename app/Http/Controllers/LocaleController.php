<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** Remembers the visitor's language for a year. Works for guests, so it stores nothing server-side. */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:'.implode(',', SetLocale::SUPPORTED)]]);

        return back()->withCookie(Cookie::make(SetLocale::COOKIE, $data['locale'], 60 * 24 * 365));
    }
}
