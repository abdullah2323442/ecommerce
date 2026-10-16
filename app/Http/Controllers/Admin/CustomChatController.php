<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomChatBlock;
use App\Models\CustomChatMessage;
use App\Models\CustomOrder;
use App\Services\Custom\CustomChat;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** We can read every chat (spec ch 23): who is talking to whom, what was blocked, the old chats searchable, and we can answer as ourselves. */
class CustomChatController extends Controller
{
    public function __construct(private CustomChat $chat) {}

    public function index(Request $request): Response
    {
        $this->authorize('handle-orders');
        $q = trim((string) $request->query('q', ''));

        $last = CustomChatMessage::select('custom_order_id', DB::raw('max(id) as last_id'), DB::raw('count(*) as total'))->groupBy('custom_order_id')->orderByDesc('last_id')->limit(60)->get();
        $messages = CustomChatMessage::whereIn('id', $last->pluck('last_id'))->get()->keyBy('custom_order_id');
        $orders = CustomOrder::whereIn('id', $last->pluck('custom_order_id'))->with('buyer:id,name,business_name')->get()->keyBy('id');
        $blocks = CustomChatBlock::select('custom_order_id', 'user_id', DB::raw('count(*) as n'))->groupBy('custom_order_id', 'user_id')->get()->groupBy('custom_order_id');

        return Inertia::render('admin/custom-chats', [
            'q' => $q,
            'chats' => $last->map(function ($row) use ($messages, $orders, $blocks) {
                $o = $orders[$row->custom_order_id] ?? null;
                $m = $messages[$row->custom_order_id] ?? null;

                return $o && $m ? ['id' => $o->id, 'number' => $o->number(), 'buyer' => $o->buyer->business_name ?: $o->buyer->name, 'status' => $o->status, 'total' => (int) $row->total, 'last' => $m->kind === 'text' ? mb_substr((string) $m->body, 0, 80) : $m->kind, 'last_role' => $m->role, 'last_at' => $m->created_at->toIso8601String(), 'slow' => $m->unanswered_at !== null, 'blocked' => (int) ($blocks[$o->id] ?? collect())->sum('n')] : null;
            })->filter()->values(),
            'found' => $q === '' ? [] : $this->chat->search($q)->map(fn (CustomChatMessage $m) => ['id' => $m->id, 'order_id' => $m->custom_order_id, 'number' => $m->order->number(), 'role' => $m->role, 'who' => $m->sender?->name, 'body' => $m->body, 'at' => $m->created_at->toIso8601String()])->values(),
            'blocks' => CustomChatBlock::latest('id')->limit(20)->get()->map(fn ($b) => ['order_id' => $b->custom_order_id, 'role' => $b->role, 'reason' => $b->reason, 'excerpt' => $b->excerpt, 'at' => $b->created_at->toIso8601String()]),
            'repeat' => CustomChatBlock::select('user_id', DB::raw('count(*) as n'))->groupBy('user_id')->having('n', '>=', 3)->get()->map(fn ($r) => ['user_id' => $r->user_id, 'name' => optional(\App\Models\User::find($r->user_id))->name, 'n' => (int) $r->n]),
        ]);
    }

    public function show(Request $request, CustomOrder $order): Response
    {
        $this->authorize('handle-orders');

        return Inertia::render('admin/custom-chat', [
            'order' => ['id' => $order->id, 'number' => $order->number(), 'status' => $order->status, 'kind' => $order->kind, 'buyer' => $order->buyer->business_name ?: $order->buyer->name, 'craftsman' => $order->craftsman?->name, 'price' => $order->price !== null ? (float) $order->price : null],
            'chat' => $this->chat->panel($order, $request->user()),
        ]);
    }

    public function send(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['text' => ['nullable', 'string', 'max:1100'], 'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'voice' => ['nullable', 'file', 'mimes:mp3,m4a,mp4,ogg,oga,wav,webm,weba', 'max:5120']]);

        return $this->act(fn () => $this->chat->send($order, $request->user(), $data['text'] ?? null, $request->file('photo'), $request->file('voice')), 'Sent.');
    }

    public function offer(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['price' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'days' => ['required', 'integer', 'min:1', 'max:365'], 'advance' => ['nullable', 'integer', 'min:0', 'max:100'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->act(fn () => $this->chat->sendOffer($order, $request->user(), (float) $data['price'], (int) $data['days'], isset($data['advance']) ? (int) $data['advance'] : null, $data['note'] ?? null), 'The offer is sent.');
    }

    public function file(CustomChatMessage $message): StreamedResponse
    {
        $this->authorize('handle-orders');
        abort_unless($message->file_path !== null && Storage::exists($message->file_path), 404);

        return Storage::response($message->file_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['chat' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
