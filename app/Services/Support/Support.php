<?php

namespace App\Services\Support;

use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use App\Services\Security\PhoneVerifier;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Support tickets. A buyer writes (no account needed, but name and phone are, as for an order); staff answer; the buyer is told and may write back.
 * A ticket is open while we owe an answer, answered while the buyer does, and closed when it is settled; a buyer who writes again within two weeks of the
 * close reopens it. The buyer's private link is the proof of whose ticket it is. Care taken against misuse: few open tickets at once, a limit on length,
 * and a ticket can only be tied to an order whose private link the buyer holds.
 */
class Support
{
    public const MAX_OPEN = 3;

    public const REOPEN_DAYS = 14;

    public const LATE_HOURS = 24;

    public function __construct(private Messenger $messages, private ActivityLogger $activity) {}

    /**
     * @param  array{name: string, phone: string, subject: string, topic: string, body: string, order_token?: ?string}  $data
     *
     * @throws StockException
     */
    public function open(array $data, ?User $by): SupportTicket
    {
        $phone = PhoneVerifier::normalise($data['phone']) ?? throw new StockException('Write a Bangladesh mobile number, like 01712345678.');
        $topic = in_array($data['topic'], SupportTicket::TOPICS, true) ? $data['topic'] : 'other';

        $open = SupportTicket::where('status', '!=', 'closed')->where(fn ($q) => $q->where('phone', $phone)->when($by, fn ($w) => $w->orWhere('user_id', $by->id)))->count();

        if ($open >= self::MAX_OPEN) {
            throw new StockException('You already have several open requests. Please wait for our answer, or add to one of them.');
        }

        // An order is tied only by its private link: the person who holds it is the person it belongs to.
        $invoice = $market = null;
        $token = trim((string) ($data['order_token'] ?? ''));

        if ($token !== '') {
            $invoice = Invoice::where('public_token', $token)->first();
            $market = $invoice ? null : MarketOrder::where('public_token', $token)->first();
        }

        return DB::transaction(function () use ($data, $by, $phone, $topic, $invoice, $market) {
            $ticket = SupportTicket::create([
                'public_token' => Str::random(40), 'user_id' => $by?->id, 'name' => mb_substr(trim($data['name']), 0, 120), 'phone' => $phone, 'subject' => mb_substr(trim($data['subject']), 0, 120),
                'topic' => $topic, 'invoice_id' => $invoice?->id, 'market_order_id' => $market?->id, 'status' => 'open', 'last_message_at' => now(), 'last_by' => 'buyer',
            ]);
            SupportMessage::create(['ticket_id' => $ticket->id, 'by' => 'buyer', 'user_id' => $by?->id, 'body' => $this->clean($data['body'])]);

            return $ticket;
        });
    }

    /**
     * The buyer writes again.
     *
     * @throws StockException
     */
    public function buyerReplies(SupportTicket $ticket, string $body): SupportTicket
    {
        if ($ticket->status === 'closed' && $ticket->closed_at !== null && $ticket->closed_at->lt(now()->subDays(self::REOPEN_DAYS))) {
            throw new StockException('This request was closed a while ago. Please open a new one.');
        }

        $reopened = $ticket->status === 'closed';
        SupportMessage::create(['ticket_id' => $ticket->id, 'by' => 'buyer', 'user_id' => $ticket->user_id, 'body' => $this->clean($body)]);
        $ticket->update(['status' => 'open', 'last_by' => 'buyer', 'last_message_at' => now(), 'closed_at' => null]);

        return $ticket->fresh();
    }

    /** @throws StockException */
    public function staffReplies(SupportTicket $ticket, User $staff, string $body): SupportTicket
    {
        if ($ticket->status === 'closed') {
            throw new StockException('This request is closed. Open it again to answer.');
        }

        SupportMessage::create(['ticket_id' => $ticket->id, 'by' => 'staff', 'user_id' => $staff->id, 'body' => $this->clean($body)]);
        $ticket->update(['status' => 'answered', 'last_by' => 'staff', 'last_message_at' => now(), 'assigned_to' => $ticket->assigned_to ?? $staff->id]);
        $this->tellBuyer($ticket);

        return $ticket->fresh();
    }

    public function assign(SupportTicket $ticket, ?User $staff): SupportTicket
    {
        $ticket->update(['assigned_to' => $staff?->id]);

        return $ticket->fresh();
    }

    public function close(SupportTicket $ticket, User $by): SupportTicket
    {
        if ($ticket->status !== 'closed') {
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);
            SupportMessage::create(['ticket_id' => $ticket->id, 'by' => 'system', 'user_id' => $by->id, 'body' => 'Closed.']);
            $this->activity->record('support.closed', null, ['ticket' => $ticket->id], $by);
        }

        return $ticket->fresh();
    }

    public function reopen(SupportTicket $ticket, User $by): SupportTicket
    {
        if ($ticket->status === 'closed') {
            $ticket->update(['status' => 'open', 'closed_at' => null, 'last_message_at' => now()]);
            SupportMessage::create(['ticket_id' => $ticket->id, 'by' => 'system', 'user_id' => $by->id, 'body' => 'Opened again.']);
        }

        return $ticket->fresh();
    }

    /** Waiting for us for more than a day. */
    public function late(SupportTicket $ticket): bool
    {
        return $ticket->status === 'open' && $ticket->last_message_at->lt(now()->subHours(self::LATE_HOURS));
    }

    /** How many are waiting for an answer. */
    public function waiting(): int
    {
        return SupportTicket::where('status', 'open')->count();
    }

    // ------------------------------------------------------------------ helpers

    /** Plain text only, cut to a sane length. */
    private function clean(string $body): string
    {
        return mb_substr(trim(strip_tags($body)), 0, 2000);
    }

    /** The buyer is told there is an answer: through their account when they have one, or by text when the owner has switched that on for this kind. */
    private function tellBuyer(SupportTicket $ticket): void
    {
        $data = ['name' => $ticket->number()];

        if ($ticket->user) {
            $this->messages->send($ticket->user, 'support_replied', $data, '/support/t/'.$ticket->public_token);

            return;
        }

        if (in_array('sms', $this->messages->channelsFor('support_replied'), true)) {
            app(\App\Services\Messages\SmsGateway::class)->send($ticket->phone, $this->messages->text('support_replied', $data, \App\Http\Middleware\SetLocale::DEFAULT).' '.url('/support/t/'.$ticket->public_token));
        }
    }
}
