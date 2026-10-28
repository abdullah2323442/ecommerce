<?php

namespace App\Services\Help;

use App\Enums\AccountType;
use App\Models\HelpComment;
use App\Models\HelpPost;
use App\Models\HelpReport;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockException;
use App\Support\MedicinePresenter;

/**
 * The Help board (spec ch 19): a place to post what you need and connect, nothing more. No payment, no escrow, no rating, no photos.
 * Every post is read by us before anyone else sees it; a person can only have so many at once; a post that is not answered for 30 days
 * is asked about and closes itself; and a post people report is taken down until we look. Who wrote a post is never shown.
 */
class HelpBoard
{
    /** A product link in a comment: ours, relative or with our own address. */
    private const PRODUCT_LINK = '~(?:https?://(?<host>[^\s/]+))?/medicines/(?<id>\d+)(?:[/?#][^\s]*)?~iu';

    private const OTHER_LINKS = '~(https?://|www\.|@[a-z0-9_]+\.|\d[\d\s-]{8,}\d)~iu';

    public function __construct(private Messenger $messages, private InvoiceService $invoices) {}

    public function activeLimit(): int
    {
        return max(1, (int) Setting::read('help.active_limit', 3));
    }

    public function dailyLimit(): int
    {
        return max(1, (int) Setting::read('help.daily_limit', 2));
    }

    public function askDays(): int
    {
        return max(1, (int) Setting::read('help.ask_days', 30));
    }

    public function graceDays(): int
    {
        return max(1, (int) Setting::read('help.grace_days', 7));
    }

    public function reportsToHide(): int
    {
        return max(1, (int) Setting::read('help.reports_to_hide', 3));
    }

    // ------------------------------------------------------------------ posting

    /** Why this person cannot post now, or null when they can. */
    public function refusal(User $user): ?string
    {
        $active = HelpPost::where('user_id', $user->id)->whereIn('status', ['pending', 'open'])->count();
        if ($active >= $this->activeLimit()) {
            return 'You already have {n} posts waiting or open. Close one first.';
        }

        if (HelpPost::where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->count() >= $this->dailyLimit()) {
            return 'That is enough posts for today. Please try again tomorrow.';
        }

        return null;
    }

    /** @param array{category: string, title: string, body: string, contact: string} $data */
    public function create(User $user, array $data): HelpPost
    {
        if ($why = $this->refusal($user)) {
            throw new StockException(str_replace('{n}', (string) $this->activeLimit(), $why));
        }

        $post = HelpPost::create(['user_id' => $user->id, 'status' => 'pending'] + $data);

        foreach (User::withPermission('customers') as $reviewer) {
            Notice::create(['user_id' => $reviewer->id, 'kind' => 'help_new', 'body' => mb_substr($post->title, 0, 290)]);
        }

        return $post;
    }

    public function approve(HelpPost $post, User $by, ?string $note = null): void
    {
        $this->review($post, $by, 'open', $note);
        $post->update(['approved_at' => now(), 'confirmed_at' => now(), 'asked_at' => null]);
        $this->messages->send($post->author, 'help_approved', ['name' => $post->title], '/help/'.$post->id);
    }

    public function reject(HelpPost $post, User $by, ?string $note = null): void
    {
        $this->review($post, $by, 'rejected', $note);
        $this->messages->send($post->author, 'help_rejected', ['name' => $post->title, 'note' => (string) $note]);
    }

    private function review(HelpPost $post, User $by, string $status, ?string $note): void
    {
        if ($post->status !== 'pending') {
            throw new StockException('This post was already looked at.');
        }

        $post->update(['status' => $status, 'reviewed_by' => $by->id, 'review_note' => $note ? mb_substr(trim($note), 0, 300) : null]);
    }

    public function close(HelpPost $post, string $by): void
    {
        if (! in_array($post->status, ['pending', 'open'], true)) {
            throw new StockException('This post is already closed.');
        }

        $post->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by]);
    }

    /** "Yes, still needed": the 30 days start again. */
    public function confirm(HelpPost $post): void
    {
        if ($post->status !== 'open') {
            throw new StockException('This post is not open.');
        }

        $post->update(['confirmed_at' => now(), 'asked_at' => null]);
    }

    // ------------------------------------------------------------------ the 30-day question

    /**
     * Ask about posts that have been open a month, and close the ones that were asked and not answered in time.
     *
     * @return array{asked: int, closed: int}
     */
    public function sweep(): array
    {
        $asked = 0;
        $cut = now()->subDays($this->askDays());

        HelpPost::where('status', 'open')->whereNull('asked_at')
            ->where(fn ($q) => $q->where('confirmed_at', '<=', $cut)->orWhere(fn ($q) => $q->whereNull('confirmed_at')->where('approved_at', '<=', $cut)))
            ->each(function (HelpPost $post) use (&$asked) {
                $post->update(['asked_at' => now()]);
                $this->messages->send($post->author, 'help_still_needed', ['name' => $post->title], '/help/'.$post->id);
                $asked++;
            });

        $closed = HelpPost::where('status', 'open')->whereNotNull('asked_at')->where('asked_at', '<=', now()->subDays($this->graceDays()))
            ->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => 'expired']);

        return ['asked' => $asked, 'closed' => $closed];
    }

    // ------------------------------------------------------------------ reports

    /** Someone says this post is a cheat or spam. Enough of them take it down until we look. */
    public function report(HelpPost $post, User $by, ?string $reason): void
    {
        if ($post->user_id === $by->id) {
            throw new StockException('You cannot report your own post.');
        }

        HelpReport::firstOrCreate(['help_post_id' => $post->id, 'user_id' => $by->id], ['reason' => $reason ? mb_substr(trim($reason), 0, 200) : null]);

        $count = $post->reports()->count();
        if ($count >= $this->reportsToHide() && ! $post->hidden) {
            $post->update(['hidden' => true]);
        }

        foreach (User::withPermission('customers') as $reviewer) {
            Notice::create(['user_id' => $reviewer->id, 'kind' => 'help_reported', 'body' => mb_substr($post->title.' ('.$count.')', 0, 290)]);
        }
    }

    // ------------------------------------------------------------------ comments

    /**
     * A comment is plain words, and product links of ours, which show as cards. Nothing else: no other links, no phone numbers.
     *
     * @throws StockException
     */
    public function comment(HelpPost $post, User $by, string $text, string $host): HelpComment
    {
        if (! $post->isShown()) {
            throw new StockException('This post is closed.');
        }

        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? '');
        $plain = $this->strip($text);
        $refused = 'Please do not write web addresses or phone numbers in a comment. A link to one of our products is fine.';

        if (preg_match(self::OTHER_LINKS, $plain)) {
            throw new StockException($refused);
        }

        if ($plain === '' && ! preg_match(self::PRODUCT_LINK, $text)) {
            throw new StockException('Write a comment first.');
        }

        // A product link on some other address is not ours.
        if (preg_match_all(self::PRODUCT_LINK, $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                // A pasted address may carry a port (a test server); the request host never does.
                $linked = preg_replace('/:\d+$/', '', $hit['host'] ?? '');
                if ($linked !== '' && strcasecmp($linked, $host) !== 0) {
                    throw new StockException($refused);
                }
            }
        }

        if (HelpComment::where('user_id', $by->id)->where('created_at', '>=', now()->subHour())->count() >= 20) {
            throw new StockException('That is a lot of comments. Please wait a little.');
        }

        return HelpComment::create(['help_post_id' => $post->id, 'user_id' => $by->id, 'body' => $text]);
    }

    /** The words of a comment without its product links. */
    private function strip(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace(self::PRODUCT_LINK, ' ', $text) ?? '') ?? '');
    }

    /**
     * What the screen draws for a comment: its words, and a card for each product linked.
     *
     * @return array{text: string, cards: list<array<string, mixed>>}
     */
    public function render(string $body, ?User $viewer): array
    {
        $cards = [];
        if (preg_match_all(self::PRODUCT_LINK, $body, $m)) {
            foreach (array_values(array_unique(array_map('intval', $m['id']))) as $id) {
                if ($card = $this->card($id, $viewer)) {
                    $cards[] = $card;
                }
            }
        }

        return ['text' => $this->strip($body), 'cards' => $cards];
    }

    /**
     * A product card: photo, name, price at the viewer's own price, and a way to open it (spec ch 19). It follows the same rules as
     * the product page: if this viewer may not see the product, there is no card at all; if it is gone, the card says so.
     *
     * @return array<string, mixed>|null
     */
    public function card(int $id, ?User $viewer): ?array
    {
        $medicine = Medicine::withTrashed()->with('company:id,name,logo_url,logo_path')->find($id);

        if ($medicine === null || $medicine->trashed() || ! $medicine->is_active) {
            return ['id' => $id, 'gone' => true];
        }

        // A bulk buyer who was not given the medicine category does not even learn it exists.
        if ($viewer?->account_type === AccountType::Wholesaler && ! $viewer->hasCategory('medicine')) {
            return null;
        }

        $kind = match ($viewer?->account_type) {
            AccountType::Paikari => 'paikari',
            AccountType::Wholesaler => 'wholesale',
            default => 'retail',
        };
        $unit = $medicine->packaging_type === 'box' ? 'box' : 'piece';
        $probe = (new Invoice(['kind' => $kind, 'channel' => 'online']))->setRelation('customer', $kind === 'retail' ? null : $viewer);
        $shape = MedicinePresenter::one($medicine);

        return [
            'id' => $medicine->id, 'gone' => false, 'name' => $shape['brand_name'], 'strength' => $shape['strength'], 'company' => $shape['company']['name'] ?? null,
            'image' => $shape['image_url'], 'unit' => $unit, 'price' => $this->invoices->rate($probe, $medicine, $unit)['price'],
        ];
    }
}
