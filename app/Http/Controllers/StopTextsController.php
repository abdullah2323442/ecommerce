<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Marketing\BulkSms;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The link at the end of a promotional text. Opening it only shows a button, so a phone that previews links cannot stop someone's texts by itself. */
class StopTextsController extends Controller
{
    public function show(int $user, string $token, BulkSms $bulk): Response
    {
        $found = User::find($user);
        abort_unless($found && hash_equals($bulk->token($user), $token), 404);

        return Inertia::render('stop-texts', ['stopped' => $found->marketing_optout_at !== null, 'token' => $token, 'user' => $user]);
    }

    public function stop(Request $request, int $user, string $token, BulkSms $bulk): Response
    {
        abort_unless($bulk->stop($user, $token), 404);

        return Inertia::render('stop-texts', ['stopped' => true, 'token' => $token, 'user' => $user]);
    }
}
