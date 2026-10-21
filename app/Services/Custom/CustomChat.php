<?php

namespace App\Services\Custom;

use App\Models\CustomChatBlock;
use App\Models\CustomChatMessage;
use App\Models\CustomOrder;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\ImageCompressor;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Chat in a made-to-order job (spec ch 23). The buyer talks to the maker under our name: the buyer never learns who the maker is, the maker never
 * sees the buyer's name or number. Text, photo and voice; two special cards (measurements, and an offer the buyer can accept); phone numbers
 * and links cannot be sent; we can read every chat; a buyer who is not answered in time brings it to us.
 */
class CustomChat
{
    public const MAX_TEXT = 1000;

    public function __construct(private CustomOrders $orders, private Messenger $messages, private ImageCompressor $images) {}

    /** Chat is a choice made for the kind of business when the owner opens it (spec ch 15); with no such sector opened, the older setting decides. */
    public function enabled(CustomOrder $order): bool
    {
        $sector = Sector::where('slug', $order->kind)->first();

        return $sector ? (bool) $sector->chat : (bool) Setting::read('custom.chat_'.$order->kind, true);
    }

    public function replyHours(): int
    {
        return max(1, (int) Setting::read('custom.reply_hours', 12));
    }

    /** Which side this person is on in this job's chat, or null when they have no place in it. */
    public function roleOf(CustomOrder $order, User $user): ?string
    {
        if ($order->buyer_id === $user->id) {
            return 'buyer';
        }

        if ($order->craftsman_id === $user->id && $user->account_type->value === 'craftsman') {
            return 'maker';
        }

        return Gate::forUser($user)->allows('handle-orders') ? 'staff' : null;
    }

    // ------------------------------------------------------------------ keeping contacts out

    /** Why this text may not be sent: a phone number ('number') or a link ('link'); null when it is fine. */
    public function problem(string $text): ?string
    {
        // Bangla digits count as digits; so do numbers broken up with spaces or dashes.
        $plain = strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        if (preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|net|org|bd|xyz|me|io|co|app|info|link|shop|store)\b|\b(t\.me|wa\.me|bit\.ly)\b|@[a-z0-9_.]{3,})~i', $plain)) {
            return 'link';
        }

        if (preg_match('/(?:\+?\d[\s\-.()]*){8,}/', $plain)) {
            return 'number';
        }

        return null;
    }

    // ------------------------------------------------------------------ sending

    /**
     * @return Collection<int, CustomChatMessage>
     *
     * @throws StockException
     */
    public function send(CustomOrder $order, User $user, ?string $text, ?UploadedFile $photo = null, ?UploadedFile $voice = null): Collection
    {
        $role = $this->open($order, $user);
        $text = $text !== null ? trim($text) : null;

        if (($text === null || $text === '') && ! $photo && ! $voice) {
            throw new StockException('Write something, add a photo or record a voice note.');
        }

        if ($text !== null && mb_strlen($text) > self::MAX_TEXT) {
            throw new StockException('That message is too long.');
        }

        if ($text) {
            if ($reason = $this->problem($text)) {
                CustomChatBlock::create(['custom_order_id' => $order->id, 'user_id' => $user->id, 'role' => $role, 'reason' => $reason, 'excerpt' => mb_substr($text, 0, 200)]);

                throw new StockException('Phone numbers and links cannot be sent here. Everything goes through this chat.');
            }
        }

        $made = collect();
        if ($text) {
            $made->push($this->add($order, $user, $role, 'text', ['body' => $text]));
        }
        if ($photo) {
            $made->push($this->add($order, $user, $role, 'photo', ['file_path' => $this->images->store($photo, 'custom-chat')]));
        }
        if ($voice) {
            $made->push($this->add($order, $user, $role, 'voice', ['file_path' => $voice->store('custom-chat')]));
        }

        $this->tellOther($order, $role);

        return $made;
    }

    /** "Send measurements": the buyer fills the form instead of typing. It replaces the measurements while nothing is agreed. */
    public function sendMeasurements(CustomOrder $order, User $buyer, array $items): CustomChatMessage
    {
        if ($this->open($order, $buyer) !== 'buyer') {
            throw new StockException('Only the buyer sends measurements.');
        }

        $order = $this->orders->replaceItems($order, $buyer, $items);
        $message = $this->add($order, $buyer, 'buyer', 'measurements', ['meta' => ['items' => $order->items]]);
        $this->tellOther($order, 'buyer');

        return $message;
    }

    /** "Send offer": we send one total and the days; the buyer taps Accept. Only we make offers, because we are the ones who know what the work costs. */
    public function sendOffer(CustomOrder $order, User $by, float $price, int $days, ?int $advance = null, ?string $note = null): CustomChatMessage
    {
        if ($this->open($order, $by) !== 'staff') {
            throw new StockException('Only we can send an offer.');
        }

        $order = $this->orders->price($order, $by, ['base_price' => $price, 'days_needed' => $days, 'advance_percent' => $advance, 'memo_note' => $note]);

        // An older offer is no longer good.
        CustomChatMessage::where('custom_order_id', $order->id)->where('kind', 'offer')->get()->each(function (CustomChatMessage $m) {
            if (($m->meta['state'] ?? null) === 'pending') {
                $m->update(['meta' => ['state' => 'superseded'] + $m->meta]);
            }
        });

        $message = $this->add($order, $by, 'staff', 'offer', ['meta' => [
            'state' => 'pending', 'total' => (float) $order->price, 'urgent_fee' => (float) $order->urgent_fee, 'days' => $order->days_needed,
            'advance_percent' => $order->advance_percent, 'note' => $note ? mb_substr(trim($note), 0, 300) : null,
        ]]);
        $this->tellOther($order, 'staff');

        return $message;
    }

    /** The buyer taps Accept (the job is confirmed) or Decline. */
    public function respondOffer(CustomChatMessage $message, User $buyer, bool $accept, bool $checked = false): CustomChatMessage
    {
        $order = $message->order;

        if ($this->open($order, $buyer) !== 'buyer' || $message->kind !== 'offer') {
            throw new StockException('This is not your offer.');
        }

        if (($message->meta['state'] ?? null) !== 'pending' || $order->status !== 'priced') {
            throw new StockException('This offer is no longer open.');
        }

        if ($accept) {
            $this->orders->confirm($order, $buyer, $checked);
        }

        $message->update(['meta' => ['state' => $accept ? 'accepted' : 'declined'] + $message->meta]);
        $this->add($order, $buyer, 'buyer', 'text', ['body' => $accept ? __('I accept the offer.') : __('I do not accept the offer.')]);

        return $message->fresh();
    }

    // ------------------------------------------------------------------ reading

    /** What each side sees: the buyer sees us, the maker sees "the buyer" and "the office", we see everything with real names. */
    public function messagesFor(CustomOrder $order, User $viewer, int $after = 0): array
    {
        $role = $this->open($order, $viewer, false);

        // A craftsman never sees what the buyer pays: the owner's price offer is between the owner and the buyer (spec ch 16).
        return CustomChatMessage::where('custom_order_id', $order->id)->where('id', '>', $after)->with('sender:id,name')->orderBy('id')->limit(300)->get()
            ->reject(fn (CustomChatMessage $m) => $role === 'maker' && $m->kind === 'offer')
            ->map(fn (CustomChatMessage $m) => $this->present($m, $role, $viewer))->values()->all();
    }

    /** The newest measurements card, for the "View measurements" shortcut at the top. */
    public function latestMeasurements(CustomOrder $order): ?array
    {
        $m = CustomChatMessage::where('custom_order_id', $order->id)->where('kind', 'measurements')->latest('id')->first();

        return $m ? ['id' => $m->id, 'items' => $m->meta['items'] ?? [], 'at' => $m->created_at->toIso8601String()] : null;
    }

    public function markRead(CustomOrder $order, string $role): void
    {
        $last = (int) CustomChatMessage::where('custom_order_id', $order->id)->max('id');

        if ($role === 'buyer') {
            $order->update(['chat_buyer_read_id' => $last]);
        } elseif ($role === 'maker') {
            $order->update(['chat_maker_read_id' => $last]);
        }
    }

    public function unread(CustomOrder $order, string $role): int
    {
        $from = $role === 'buyer' ? ['maker', 'staff'] : ['buyer', 'staff'];
        $read = $role === 'buyer' ? $order->chat_buyer_read_id : $order->chat_maker_read_id;

        return CustomChatMessage::where('custom_order_id', $order->id)->where('id', '>', $read)->whereIn('role', $from)->count();
    }

    /** @return array<string, mixed> */
    public function present(CustomChatMessage $m, string $viewerRole, User $viewer): array
    {
        $mine = $m->sender_id === $viewer->id;

        $who = match ($viewerRole) {
            // The buyer is talking to us, whoever answers.
            'buyer' => $mine ? __('You') : (string) config('brand.name', config('app.name')),
            'maker' => $mine ? __('You') : ($m->role === 'buyer' ? __('The buyer') : __('The office')),
            default => ($m->role === 'buyer' ? __('The buyer') : ($m->sender?->name ?? __('The office'))).' ('.$m->role.')',
        };

        return [
            'id' => $m->id, 'mine' => $mine, 'role' => $m->role, 'who' => $who, 'kind' => $m->kind, 'body' => $m->body, 'has_file' => $m->file_path !== null, 'meta' => $m->meta,
            'at' => $m->created_at->toIso8601String(), 'slow' => $m->unanswered_at !== null && $viewerRole === 'staff',
        ];
    }

    /** Everything a page needs to show the chat to this person. */
    public function panel(CustomOrder $order, User $viewer): array
    {
        $role = $this->open($order, $viewer, false);

        return [
            'enabled' => $this->enabled($order), 'role' => $role, 'messages' => $this->messagesFor($order, $viewer), 'measurements' => $this->latestMeasurements($order),
            'closed' => in_array($order->status, ['cancelled'], true), 'can_measure' => $role === 'buyer' && in_array($order->status, ['requested', 'priced'], true),
            'can_offer' => $role === 'staff' && in_array($order->status, ['requested', 'priced'], true),
        ];
    }

    // ------------------------------------------------------------------ watching

    /** A buyer who wrote and was not answered in time: we are told once for that message. Returns how many. */
    public function watch(): int
    {
        $n = 0;
        $cut = now()->subHours($this->replyHours());

        CustomOrder::whereIn('status', CustomOrder::OPEN)->whereHas('chat')->each(function (CustomOrder $o) use ($cut, &$n) {
            $last = CustomChatMessage::where('custom_order_id', $o->id)->latest('id')->first();

            if ($last && $last->role === 'buyer' && $last->kind !== 'measurements' && $last->created_at->lte($cut) && $last->unanswered_at === null) {
                $last->update(['unanswered_at' => now()]);
                foreach (User::withPermission('orders') as $staff) {
                    Notice::create(['user_id' => $staff->id, 'kind' => 'custom_unanswered', 'body' => mb_substr($o->number().' has a chat message waiting for an answer', 0, 290)]);
                }
                $n++;
            }
        });

        return $n;
    }

    /** Messages for the owner's search of old chats. @return Collection<int, CustomChatMessage> */
    public function search(string $q, int $limit = 60): Collection
    {
        $q = trim($q);

        return CustomChatMessage::with(['order:id,created_at', 'sender:id,name'])->where('kind', 'text')->where('body', 'like', '%'.addcslashes($q, '%_\\').'%')->latest('id')->limit($limit)->get();
    }

    // ------------------------------------------------------------------ helpers

    /** @throws StockException */
    private function open(CustomOrder $order, User $user, bool $forSending = true): string
    {
        $role = $this->roleOf($order, $user) ?? throw new StockException('This is not your chat.');

        if ($forSending) {
            if (! $this->enabled($order)) {
                throw new StockException('Chat is not open for this kind of job.');
            }

            if ($order->status === 'cancelled') {
                throw new StockException('This job is closed.');
            }
        }

        return $role;
    }

    private function add(CustomOrder $order, User $user, string $role, string $kind, array $data): CustomChatMessage
    {
        return CustomChatMessage::create(['custom_order_id' => $order->id, 'sender_id' => $user->id, 'role' => $role, 'kind' => $kind] + $data);
    }

    /** The other side hears about it, once until they have looked (so a flood of messages is one notice). */
    private function tellOther(CustomOrder $order, string $from): void
    {
        $order->refresh();

        if ($from === 'buyer') {
            if ($order->craftsman && $this->unread($order, 'maker') <= 1) {
                $this->messages->send($order->craftsman, 'craftsman_chat', ['name' => $order->number()], '/craftsman/jobs/'.$order->id);
            }

            return;
        }

        if ($this->unread($order, 'buyer') <= 1) {
            $this->messages->send($order->buyer, 'custom_chat', ['name' => $order->number()], '/custom/'.$order->public_token);
        }
    }
}
