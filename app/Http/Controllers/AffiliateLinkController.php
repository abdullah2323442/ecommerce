<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Services\Marketing\Affiliates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The link an affiliate shares (/a/CODE) and the affiliate's own page: opening the link keeps the code on the visitor for a while, then sends them on. */
class AffiliateLinkController extends Controller
{
    public function __construct(private Affiliates $affiliates) {}

    /** The visitor lands on the home page, or on a page of this site that the affiliate pointed to (never anywhere else). */
    public function visit(Request $request, string $code): RedirectResponse
    {
        $kept = $this->affiliates->click($code);
        $to = (string) $request->query('to', '/');
        $to = str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\') ? $to : '/';
        $response = redirect($to);

        return $kept ? $response->withCookie(cookie(Affiliates::COOKIE, $kept, $this->affiliates->days() * 1440, '/', null, null, true, false, 'lax')) : $response;
    }

    /** An affiliate with an account sees their own link and earnings. */
    public function mine(Request $request): Response
    {
        $affiliate = Affiliate::where('user_id', $request->user()->id)->firstOrFail();

        return Inertia::render('affiliate', ['affiliate' => $this->affiliates->statement($affiliate)]);
    }
}
