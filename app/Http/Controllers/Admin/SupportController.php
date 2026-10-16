<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\SupportTicket;
use App\Services\Stock\StockException;
use App\Services\Support\Support;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The office answers support tickets (spec ch 29). */
class SupportController extends Controller
{
    public function __construct(private Support $support) {}

    public function index(Request $request): Response
    {
        $this->authorize('handle-orders');
        $status = in_array($request->query('status'), ['open', 'answered', 'closed'], true) ? (string) $request->query('status') : 'open';
        $q = trim((string) $request->query('q', ''));
        $mine = $request->boolean('mine');

        $rows = SupportTicket::where('status', $status)
            ->when($mine, fn ($w) => $w->where('assigned_to', $request->user()->id))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('subject', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('phone', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->with('assignee:id,name')->oldest('last_message_at')->paginate(25)->withQueryString()
            ->through(fn (SupportTicket $t) => [
                'id' => $t->id, 'number' => $t->number(), 'subject' => $t->subject, 'name' => $t->name, 'phone' => $t->phone, 'topic' => $t->topic, 'status' => $t->status, 'at' => $t->last_message_at->toIso8601String(),
                'assignee' => $t->assignee?->name, 'late' => $this->support->late($t),
            ]);

        return Inertia::render('admin/support/index', [
            'tickets' => $rows, 'status' => $status, 'q' => $q, 'mine' => $mine,
            'counts' => ['open' => SupportTicket::where('status', 'open')->count(), 'answered' => SupportTicket::where('status', 'answered')->count(), 'closed' => SupportTicket::where('status', 'closed')->count()],
        ]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $this->authorize('handle-orders');
        $invoice = $ticket->invoice_id ? Invoice::find($ticket->invoice_id) : null;
        $market = $ticket->market_order_id ? MarketOrder::find($ticket->market_order_id) : null;

        return Inertia::render('admin/support/show', [
            'ticket' => ['id' => $ticket->id, 'number' => $ticket->number(), 'subject' => $ticket->subject, 'name' => $ticket->name, 'phone' => $ticket->phone, 'topic' => $ticket->topic, 'status' => $ticket->status, 'account' => $ticket->user_id !== null,
                'assignee' => $ticket->assignee?->name, 'assigned_to_me' => $ticket->assigned_to === request()->user()->id, 'late' => $this->support->late($ticket),
                'order' => $invoice ? ['label' => $invoice->number ?? 'ORD'.$invoice->id, 'url' => '/admin/orders/'.$invoice->id] : ($market ? ['label' => $market->number(), 'url' => null] : null)],
            'messages' => $ticket->messages()->with('user:id,name')->oldest('id')->get()->map(fn ($m) => ['id' => $m->id, 'by' => $m->by, 'who' => $m->by === 'staff' ? $m->user?->name : null, 'body' => $m->by === 'system' ? __($m->body) : $m->body, 'at' => $m->created_at->toIso8601String()]),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);

        return $this->attempt(fn () => $this->support->staffReplies($ticket, $request->user(), $data['body']), 'Sent to the buyer.');
    }

    public function assign(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('handle-orders');
        $this->support->assign($ticket, $request->boolean('take') ? $request->user() : null);

        return back()->with('status', __('Saved.'));
    }

    public function close(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('handle-orders');
        $this->support->close($ticket, $request->user());

        return back()->with('status', __('Closed.'));
    }

    public function reopen(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('handle-orders');
        $this->support->reopen($ticket, $request->user());

        return back()->with('status', __('Opened again.'));
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['support' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
