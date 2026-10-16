<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\CustomMember;
use App\Models\CustomOrder;
use App\Models\CustomProgress;
use App\Models\User;
use App\Models\CustomChatMessage;
use App\Services\Custom\CustomChat;
use App\Services\Custom\Craftsmen;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A craftsman's own screen (spec ch 16.4): the jobs they are on, with measurements, photos, deadline and their own wages. Never what the
 * buyer pays, what we make, the buyer's phone, another craftsman's jobs, or stock. The section gate keeps everyone else out.
 */
class CraftsmanController extends Controller
{
    public function __construct(private Craftsmen $craftsmen, private CustomChat $chat) {}

    public function index(Request $request): Response
    {
        $me = $request->user();
        $mine = CustomMember::where('craftsman_id', $me->id)->where('active', true)->with('order')->get()->pluck('order')->filter();
        $row = fn (CustomOrder $o) => ['id' => $o->id, 'number' => $o->number(), 'kind' => $o->kind, 'status' => $o->status, 'due_on' => $o->due_on?->toDateString(), 'done' => $this->craftsmen->done($o), 'waiting' => $o->waiting_note !== null, 'is_main' => $this->craftsmen->isMain($o, $me), 'urgent' => $o->urgent];

        return Inertia::render('craftsman/index', [
            'jobs' => $mine->filter(fn (CustomOrder $o) => in_array($o->status, ['confirmed', 'making', 'trial'], true))->map($row)->values(),
            'finished' => $mine->filter(fn (CustomOrder $o) => in_array($o->status, ['done', 'collected'], true))->sortByDesc('id')->take(15)->map($row)->values(),
            'asks' => CustomOrder::where('wage_asked_id', $me->id)->whereNull('craftsman_id')->whereIn('status', ['requested', 'priced', 'confirmed'])->get()->map($row)->values(),
            'ledger' => $this->craftsmen->ledger($me),
        ]);
    }

    public function show(Request $request, CustomOrder $order): Response
    {
        $this->see($request, $order);

        // Only the one mainly responsible talks to the buyer, and only under our name.
        $panel = $this->chat->roleOf($order, $request->user()) === 'maker' ? $this->chat->panel($order, $request->user()) : null;
        if ($panel && ! $request->header('X-Inertia-Partial-Data')) {
            $this->chat->markRead($order, 'maker');
        }

        return Inertia::render('craftsman/show', [
            'chat' => $panel,
            'job' => $this->craftsmen->jobFor($order, $request->user()),
            'others' => User::where('account_type', AccountType::Craftsman)->where('id', '!=', $request->user()->id)->whereNull('suspended_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function propose(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->see($request, $order);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'note' => ['nullable', 'string', 'max:300']]);

        return $this->act(fn () => $this->craftsmen->propose($order, $request->user(), (float) $data['amount'], $data['note'] ?? null), 'Sent. We will confirm the wage.');
    }

    public function record(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->see($request, $order);
        $data = $request->validate([
            'craftsman_id' => ['required', 'integer', 'exists:users,id'], 'percent' => ['required', 'numeric', 'gt:0', 'max:100'], 'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'], 'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->act(fn () => $this->craftsmen->record($order, $request->user(), User::findOrFail($data['craftsman_id']), (float) $data['percent'], $request->file('photo'), $data['note'] ?? null), 'Saved.');
    }

    public function shift(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->see($request, $order);
        $data = $request->validate(['to' => ['required', 'integer', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->act(fn () => $this->craftsmen->shift($order, $request->user(), User::findOrFail($data['to']), $data['note'] ?? null), 'The job is handed on.');
    }

    public function share(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->see($request, $order);
        $data = $request->validate(['to' => ['required', 'integer', 'exists:users,id'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->act(fn () => $this->craftsmen->share($order, $request->user(), User::findOrFail($data['to']), $data['note'] ?? null), 'Added to the job.');
    }

    public function waiting(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->see($request, $order);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:200']]);

        return $this->act(fn () => $this->craftsmen->materialsNeeded($order, $request->user(), $data['note']), 'We have been told.');
    }

    public function chatSend(Request $request, CustomOrder $order): RedirectResponse
    {
        $this->see($request, $order);
        $data = $request->validate(['text' => ['nullable', 'string', 'max:1100'], 'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'voice' => ['nullable', 'file', 'mimes:mp3,m4a,mp4,ogg,oga,wav,webm,weba', 'max:5120']]);

        return $this->act(fn () => $this->chat->send($order, $request->user(), $data['text'] ?? null, $request->file('photo'), $request->file('voice')), 'Sent.');
    }

    public function chatFile(Request $request, CustomOrder $order, CustomChatMessage $message): StreamedResponse
    {
        $this->see($request, $order);
        abort_unless($this->chat->roleOf($order, $request->user()) === 'maker' && $message->custom_order_id === $order->id, 404);

        return $this->send($message->file_path);
    }

    public function jobPhoto(Request $request, CustomOrder $order): StreamedResponse
    {
        $this->see($request, $order);

        return $this->send($order->photo_path);
    }

    public function progressPhoto(Request $request, CustomProgress $entry): StreamedResponse
    {
        $this->see($request, $entry->order);

        return $this->send($entry->photo_path);
    }

    private function send(?string $path): StreamedResponse
    {
        abort_unless($path !== null && Storage::exists($path), 404);

        return Storage::response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Only a job they are on, or were asked the wage of: changing the number in the address never shows another's. */
    private function see(Request $request, CustomOrder $order): void
    {
        if (! $this->craftsmen->canSee($order, $request->user())) {
            throw new AuthorizationException;
        }
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['job' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }
}
