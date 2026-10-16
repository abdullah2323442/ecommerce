<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Notice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A customer's inbox (spec ch 23): the messages about their orders and account, urgent ones first, then the newest. It is for
 * customer accounts; staff and the owner have their own screens for what they need to act on.
 */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $this->customer($request);

        return Inertia::render('inbox', [
            'messages' => Notice::where('user_id', $request->user()->id)->orderByRaw("case importance when 'urgent' then 0 when 'normal' then 1 else 2 end")->latest('id')->limit(100)->get()
                ->map(fn (Notice $n) => [
                    'id' => $n->id, 'kind' => $n->kind, 'importance' => $n->importance, 'body' => $n->body, 'read' => $n->read_at !== null, 'when' => $n->created_at?->toIso8601String(),
                    'link' => json_decode($n->body, true)['link'] ?? null,
                ]),
            'home' => $request->user()->account_type->homePath(),
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->customer($request);
        Notice::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    private function customer(Request $request): void
    {
        if (! in_array($request->user()?->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }
    }
}
