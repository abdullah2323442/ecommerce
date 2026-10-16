<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\CustomMaterial;
use App\Models\CustomOrder;
use App\Models\MeasurementProfile;
use App\Models\CustomChatMessage;
use App\Services\Custom\CustomChat;
use App\Services\Custom\CustomOrders;
use App\Services\Custom\MeasurementProfiles;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Made-to-order work, from the buyer's side (spec ch 16): anyone who buys can ask for a piece in wood or clothing with their own
 * measurements. No return, no commission. The buyer never sees what the material or the wages cost us.
 */
class CustomOrderController extends Controller
{
    public function __construct(private CustomOrders $orders, private MeasurementProfiles $profiles, private CustomChat $chat) {}

    public function index(Request $request): Response
    {
        $this->buyer($request);

        return Inertia::render('custom/index', [
            'orders' => CustomOrder::where('buyer_id', $request->user()->id)->latest('id')->limit(50)->get()
                ->map(fn (CustomOrder $o) => ['token' => $o->public_token, 'number' => $o->number(), 'kind' => $o->kind, 'status' => $o->status, 'price' => $o->price !== null ? (float) $o->price : null, 'at' => $o->created_at->toIso8601String()]),
            'profileCount' => MeasurementProfile::where('user_id', $request->user()->id)->count(),
        ]);
    }

    public function create(Request $request, string $kind): Response
    {
        $this->buyer($request);
        abort_unless(in_array($kind, (array) config('custom.kinds'), true), 404);

        return Inertia::render('custom/new', [
            'kind' => $kind,
            'materials' => CustomMaterial::where('kind', $kind === 'wood' ? 'wood' : 'fabric')->where('is_active', true)->orderBy('sort')->orderBy('name')
                ->get()->map(fn (CustomMaterial $m) => ['id' => $m->id, 'name' => app()->getLocale() === 'bn' && $m->name_bn ? $m->name_bn : $m->name, 'rate' => (float) $m->rate]),
            'profiles' => MeasurementProfile::where('user_id', $request->user()->id)->orderBy('name')->get()->map(fn ($p) => $this->profiles->present($p)),
            'fields' => config('custom.fields'), 'woodOptions' => config('custom.wood_options'), 'clothingOptions' => config('custom.clothing_options'), 'units' => config('custom.units'),
            'urgentPercent' => $this->orders->urgentPercent(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->buyer($request);
        $data = $request->validate([
            'kind' => ['required', 'in:wood,clothing'], 'material_id' => ['nullable', 'integer'], 'fabric_by' => ['nullable', 'in:shop,buyer'],
            'items' => ['required', 'array', 'min:1', 'max:20'], 'details' => ['nullable', 'array'], 'note' => ['nullable', 'string', 'max:1000'], 'urgent' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        try {
            $order = $this->orders->place($request->user(), $data, $request->file('photo'));
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return to_route('custom.show', $order->public_token)->with('status', __('Sent. We will check the measurements and send you a price.'));
    }

    public function show(Request $request, string $token): Response
    {
        $order = $this->mine($request, $token);

        $panel = $this->chat->panel($order, $request->user());
        // Opening the page is reading the chat; a poll (partial reload of the chat only) does not count as a visit.
        if (! $request->header('X-Inertia-Partial-Data')) {
            $this->chat->markRead($order, 'buyer');
        }

        return Inertia::render('custom/show', [
            'order' => $this->orders->forBuyer($order->load('events')),
            'chat' => $panel,
            'measure' => $panel['can_measure'] ? ['kind' => $order->kind, 'profiles' => \App\Models\MeasurementProfile::where('user_id', $request->user()->id)->orderBy('name')->get()->map(fn ($p) => $this->profiles->present($p)), 'fields' => config('custom.fields'), 'units' => config('custom.units')] : null,
        ]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['checked' => ['required', 'boolean']]);

        return $this->act(fn () => $this->orders->confirm($this->mine($request, $token), $request->user(), (bool) $data['checked']), 'Thank you. We will tell you when to pay the advance and when we start.');
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return $this->act(fn () => $this->orders->cancel($this->mine($request, $token), $request->user(), 'buyer', $data['reason'] ?? null), 'Cancelled.');
    }

    public function complain(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->act(fn () => $this->orders->complain($this->mine($request, $token), $request->user(), $data['text']), 'Sent. We will look at it.');
    }

    public function chat(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['text' => ['nullable', 'string', 'max:1100'], 'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'voice' => ['nullable', 'file', 'mimes:mp3,m4a,mp4,ogg,oga,wav,webm,weba', 'max:5120']]);

        return $this->act(fn () => $this->chat->send($this->mine($request, $token), $request->user(), $data['text'] ?? null, $request->file('photo'), $request->file('voice')), 'Sent.');
    }

    /** "Send measurements": the form in the chat. */
    public function measurements(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1', 'max:20']]);

        return $this->act(fn () => $this->chat->sendMeasurements($this->mine($request, $token), $request->user(), $data['items']), 'Sent. We will check them and send you a price.');
    }

    public function offer(Request $request, string $token, CustomChatMessage $message): RedirectResponse
    {
        $order = $this->mine($request, $token);
        abort_unless($message->custom_order_id === $order->id, 404);
        $data = $request->validate(['answer' => ['required', 'in:accept,decline'], 'checked' => ['nullable', 'boolean']]);

        return $this->act(fn () => $this->chat->respondOffer($message->setRelation('order', $order), $request->user(), $data['answer'] === 'accept', (bool) ($data['checked'] ?? false)), $data['answer'] === 'accept' ? 'Thank you. We will tell you when to pay the advance and when we start.' : 'Noted.');
    }

    public function chatFile(Request $request, string $token, CustomChatMessage $message): StreamedResponse
    {
        $order = $this->mine($request, $token);
        abort_unless($message->custom_order_id === $order->id && $message->file_path !== null && Storage::exists($message->file_path), 404);

        return Storage::response($message->file_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function photo(Request $request, string $token): StreamedResponse
    {
        $order = $this->mine($request, $token);
        abort_unless($order->photo_path !== null && Storage::exists($order->photo_path), 404);

        return Storage::response($order->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    /** Only the buyer's own job: changing the link never shows someone else's. */
    private function mine(Request $request, string $token): CustomOrder
    {
        $this->buyer($request);
        $order = CustomOrder::where('public_token', $token)->firstOrFail();

        if ($order->buyer_id !== $request->user()->id) {
            throw new AuthorizationException;
        }

        return $order;
    }

    private function buyer(Request $request): void
    {
        if (! in_array($request->user()?->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }
    }
}
