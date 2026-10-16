<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\ListingQuestion;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;

/**
 * Questions under a seller's product (spec ch 23). The buyer asks, the seller is told and answers, everyone reads the answer.
 * A question the seller leaves too long comes to us to answer, and each one counts against the seller on their profile.
 */
class ListingQuestions
{
    /** Few a day: this is a question, not a chat. */
    private const PER_DAY = 10;

    public function __construct(private Messenger $messages) {}

    public function answerHours(): int
    {
        return max(1, (int) Setting::read('market.answer_hours', 24));
    }

    public function slowLimit(): int
    {
        return max(1, (int) Setting::read('market.slow_limit', 3));
    }

    /** @throws StockException */
    public function ask(Listing $listing, User $asker, string $text): ListingQuestion
    {
        if ($asker->id === $listing->seller_id) {
            throw new StockException('You cannot ask a question about your own product.');
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($text) < 8) {
            throw new StockException('Write a little more, so we can answer.');
        }

        // The talk stays on this site: no addresses, no numbers to call.
        if (preg_match('~(https?://|www\.|@[a-z0-9_]+\.|\d[\d\s-]{8,}\d)~iu', $text)) {
            throw new StockException('Please do not write web addresses or phone numbers in a question.');
        }

        if (ListingQuestion::where('user_id', $asker->id)->where('created_at', '>=', now()->subDay())->count() >= self::PER_DAY) {
            throw new StockException('That is enough questions for today. Please try again tomorrow.');
        }

        $question = ListingQuestion::create(['listing_id' => $listing->id, 'seller_id' => $listing->seller_id, 'user_id' => $asker->id, 'question' => mb_substr($text, 0, 500)]);

        $this->messages->send($listing->seller, 'listing_question_new', ['name' => $listing->title], '/wholesale/sell/questions');

        return $question;
    }

    /** The seller answers their own; our staff may answer any (and edit an answer). The asker is told once. */
    public function answer(ListingQuestion $question, User $by, string $text, bool $staff = false): ListingQuestion
    {
        if (! $staff && $question->seller_id !== $by->id) {
            throw new StockException('This is not your question.');
        }

        $text = trim($text);

        if (mb_strlen($text) < 2) {
            throw new StockException('Write the answer.');
        }

        $first = $question->answered_at === null;
        $question->update(['answer' => mb_substr($text, 0, 1500), 'answered_by' => $by->id, 'answered_at' => $question->answered_at ?? now(), 'hidden' => false]);

        if ($first) {
            $this->messages->send($question->asker, 'listing_question_answered', ['name' => $question->listing->title], '/market/'.$question->listing_id);
        }

        return $question->fresh();
    }

    public function hide(ListingQuestion $question, bool $hidden): ListingQuestion
    {
        $question->update(['hidden' => $hidden]);

        return $question->fresh();
    }

    /**
     * Questions the seller left too long come to us (spec ch 23). The seller is told once and the staff see it in the questions list.
     *
     * @return int how many came to us now
     */
    public function escalate(): int
    {
        $count = 0;

        ListingQuestion::waiting()->whereNull('escalated_at')->where('created_at', '<=', now()->subHours($this->answerHours()))->with('listing:id,title', 'seller')->get()
            ->each(function (ListingQuestion $q) use (&$count) {
                $q->update(['escalated_at' => now()]);
                $this->messages->send($q->seller, 'listing_question_late', ['name' => $q->listing->title], '/wholesale/sell/questions');

                foreach (User::withPermission('orders') as $staff) {
                    Notice::create(['user_id' => $staff->id, 'kind' => 'question_new', 'body' => mb_substr($q->listing->title.': '.$q->question, 0, 290)]);
                }
                $count++;
            });

        return $count;
    }

    /** Questions that came to us from this seller in the last 60 days: the mark on their profile (spec ch 23). */
    public function slow(User $seller): int
    {
        return ListingQuestion::where('seller_id', $seller->id)->where('escalated_at', '>=', now()->subDays(60))->count();
    }

    /** The answered questions shown under a listing. */
    public function shown(Listing $listing): array
    {
        return ListingQuestion::public()->where('listing_id', $listing->id)->latest('answered_at')->limit(20)->get()
            ->map(fn (ListingQuestion $q) => ['id' => $q->id, 'question' => $q->question, 'answer' => $q->answer, 'at' => $q->answered_at->toIso8601String()])->all();
    }
}
