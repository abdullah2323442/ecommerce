<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\Stock\StockException;
use App\Services\Support\Support;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The buyer's side of support: write to the shop, follow the answer from a private link, write back (spec ch 29). */
class SupportController extends Controller
{
    public function __construct(private Support $support) {}

    /** The signed-in person's own requests. */
    public function index(Request $request): Response
    {
        return Inertia::render('support/index', [
            'tickets' => SupportTicket::where('user_id', $request->user()->id)->latest('last_message_at')->limit(50)->get()
                ->map(fn (SupportTicket $t) => ['token' => $t->public_token, 'number' => $t->number(), 'subject' => $t->subject, 'status' => $t->status, 'at' => $t->last_message_at->toIso8601String()]),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('support/new', [
            'defaults' => ['name' => $user?->name, 'phone' => $user?->phone],
            'topics' => SupportTicket::TOPICS,
            'orderToken' => preg_match('/^[A-Za-z0-9]{40}$/', (string) $request->query('order')) ? (string) $request->query('order') : null,
            'topic' => in_array($request->query('topic'), SupportTicket::TOPICS, true) ? (string) $request->query('topic') : 'other',
            'signedIn' => $user !== null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:20'], 'subject' => ['required', 'string', 'min:3', 'max:120'],
            'topic' => ['required', 'string', 'max:12'], 'body' => ['required', 'string', 'min:5', 'max:2000'], 'order_token' => ['nullable', 'string', 'size:40'],
        ]);

        try {
            $ticket = $this->support->open($data, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['support' => __($e->getMessage())])->withInput();
        }

        return to_route('support.show', $ticket->public_token)->with('status', __('We received your request. We will answer here. Keep this page address to come back to it.'));
    }

    public function show(string $token): Response
    {
        $ticket = $this->find($token);

        return Inertia::render('support/show', [
            'token' => $ticket->public_token,
            'ticket' => ['number' => $ticket->number(), 'subject' => $ticket->subject, 'status' => $ticket->status, 'topic' => $ticket->topic, 'at' => $ticket->created_at->toIso8601String(),
                'can_reply' => $ticket->status !== 'closed' || ($ticket->closed_at !== null && $ticket->closed_at->gte(now()->subDays(Support::REOPEN_DAYS)))],
            'messages' => $ticket->messages()->oldest('id')->get()->map(fn ($m) => ['id' => $m->id, 'by' => $m->by, 'body' => $m->by === 'system' ? __($m->body) : $m->body, 'at' => $m->created_at->toIso8601String()]),
        ]);
    }

    public function reply(Request $request, string $token): RedirectResponse
    {
        $ticket = $this->find($token);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);

        try {
            $this->support->buyerReplies($ticket, $data['body']);
        } catch (StockException $e) {
            return back()->withErrors(['support' => __($e->getMessage())]);
        }

        return back()->with('status', __('Sent.'));
    }

    private function find(string $token): SupportTicket
    {
        return SupportTicket::where('public_token', $token)->firstOrFail();
    }
}
