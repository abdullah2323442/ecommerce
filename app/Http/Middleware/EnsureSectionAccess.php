<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Enums\Section;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route group to a Section (spec VIS-3, VIS-4).
 *
 * A denied request is redirected to the public homepage with no flash message, no 403 and
 * no login prompt, so a paikari buyer can never learn that a wholesale section exists.
 * Guests are treated as the public account type.
 */
class EnsureSectionAccess
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        $type = $request->user()?->account_type ?? AccountType::Public;
        $target = Section::from($section);

        if ($request->user()?->isSuspended() || ! $type->canSee($target)) {
            return redirect('/');
        }

        return $next($request);
    }
}
