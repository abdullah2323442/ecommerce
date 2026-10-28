<?php

namespace App\Services\Marketing;

use App\Jobs\SendCampaignSms;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\Setting;
use App\Models\SmsCampaign;
use App\Models\SmsRecipient;
use App\Models\Tier;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Ledger\Receivables;
use App\Services\Messages\ConfiguredSmsGateway;
use App\Services\Stock\StockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk text messages (spec ch 27, bulk SMS): the owner writes one message, chooses who it is for (shop type, tier, area, who has not ordered for a while, who owes
 * money), sees how many people and how many text segments that is, sends a test to their own phone, and then sends. Sending goes out slowly in the queue through
 * the text service switched on in Connections (and only logs while that service is in test mode, so nothing is spent by mistake). Everyone can say stop: each
 * message ends with a short link that switches promotions off for that number, and a person who did that is never in an audience again. A daily limit (a
 * setting) keeps a slip from costing a fortune.
 */
class BulkSms
{
    public const MAX_BODY = 450;

    public const TYPES = ['public', 'paikari', 'wholesaler'];

    public function __construct(private ActivityLogger $activity, private ConfiguredSmsGateway $gateway) {}

    // ------------------------------------------------------------------ settings

    public function dailyLimit(): int
    {
        return max(0, (int) Setting::read('sms.bulk_daily_limit', 2000));
    }

    /** Messages a second, so a slow text service is not flooded. */
    public function perSecond(): int
    {
        return max(1, (int) Setting::read('sms.bulk_per_second', 5));
    }

    // ------------------------------------------------------------------ who

    /**
     * Only what is understood from the screen: unknown tiers, types and nonsense are dropped.
     *
     * @param  array<string, mixed>  $in
     * @return array{types: list<string>, tier_ids: list<int>, area: string, inactive_days: ?int, owes: bool}
     */
    public function filters(array $in): array
    {
        $types = array_values(array_intersect(self::TYPES, array_map('strval', (array) ($in['types'] ?? []))));
        $tiers = Tier::whereIn('id', array_map('intval', (array) ($in['tier_ids'] ?? [])))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $days = isset($in['inactive_days']) && $in['inactive_days'] !== '' ? (int) $in['inactive_days'] : null;

        return [
            'types' => $types, 'tier_ids' => $tiers, 'area' => mb_substr(trim((string) ($in['area'] ?? '')), 0, 80),
            'inactive_days' => $days !== null && $days >= 7 && $days <= 730 ? $days : null, 'owes' => (bool) ($in['owes'] ?? false),
        ];
    }

    /** @param  array{types: list<string>, tier_ids: list<int>, area: string, inactive_days: ?int, owes: bool}  $f */
    public function label(array $f): string
    {
        $parts = [];
        $names = ['public' => __('Public'), 'paikari' => __('Paikari'), 'wholesaler' => __('Wholesale')];
        $parts[] = $f['types'] === [] ? __('Everyone with a mobile number') : implode(', ', array_map(fn ($t) => $names[$t], $f['types']));

        if ($f['tier_ids'] !== []) {
            $parts[] = __('tier {names}', ['names' => Tier::whereIn('id', $f['tier_ids'])->orderBy('name')->pluck('name')->implode(', ')]);
        }

        if ($f['area'] !== '') {
            $parts[] = __('in {area}', ['area' => $f['area']]);
        }

        if ($f['inactive_days'] !== null) {
            $parts[] = __('no order for {n} days', ['n' => $f['inactive_days']]);
        }

        if ($f['owes']) {
            $parts[] = __('owing us money');
        }

        return mb_substr(implode(', ', $parts), 0, 250);
    }

    /**
     * @param  array{types: list<string>, tier_ids: list<int>, area: string, inactive_days: ?int, owes: bool}  $f
     * @return Builder<User>
     */
    private function query(array $f): Builder
    {
        $types = $f['types'] === [] ? self::TYPES : $f['types'];

        $q = User::query()->whereIn('account_type', $types)->whereNull('suspended_at')->whereNull('marketing_optout_at')
            ->whereNotNull('phone')->whereRaw('length(phone) = 11')->where('phone', 'like', '01%');

        if ($f['tier_ids'] !== []) {
            $q->whereIn('tier_id', $f['tier_ids']);
        }

        if ($f['area'] !== '') {
            $q->where('area', 'like', '%'.addcslashes($f['area'], '%_\\').'%');
        }

        if ($f['inactive_days'] !== null) {
            $cut = now()->subDays($f['inactive_days']);
            $q->where('created_at', '<', $cut)
                ->whereNotIn('id', Invoice::where('created_at', '>=', $cut)->where('status', '!=', 'cancelled')->whereNotNull('customer_id')->select('customer_id'))
                ->whereNotIn('id', MarketOrder::where('created_at', '>=', $cut)->where('status', '!=', 'cancelled')->whereNotNull('buyer_id')->select('buyer_id'));
        }

        if ($f['owes']) {
            $q->whereIn('id', app(Receivables::class)->owing()->pluck('id')->all());
        }

        return $q;
    }

    /**
     * Who would get it: users with a real Bangladesh mobile number, one row for each number (two accounts sharing a number get one text).
     *
     * @param  array{types: list<string>, tier_ids: list<int>, area: string, inactive_days: ?int, owes: bool}  $f
     * @return Collection<int, array{id: int, phone: string}>
     */
    public function people(array $f): Collection
    {
        return $this->query($f)->orderBy('id')->get(['id', 'phone'])
            ->filter(fn (User $u) => (bool) preg_match('/^01[3-9]\d{8}$/', (string) $u->phone))
            ->unique('phone')->map(fn (User $u) => ['id' => $u->id, 'phone' => (string) $u->phone])->values();
    }

    // ------------------------------------------------------------------ the words

    /** The line that lets a person stop promotions. It is part of every message, so it counts towards its length. */
    public function footer(?User $user = null): string
    {
        return "\n".__('To stop these messages:').' '.($user ? $this->stopLink($user) : url('/u/0/stop'));
    }

    public function stopLink(User $user): string
    {
        return url('/u/'.$user->id.'/'.$this->token($user->id));
    }

    public function token(int $userId): string
    {
        return substr(hash_hmac('sha256', 'sms-stop:'.$userId, (string) config('app.key')), 0, 12);
    }

    /** How many text segments one message takes: Bangla needs the long alphabet, so 70 letters make a segment (67 once a message needs more than one). */
    public function segments(string $text): int
    {
        $length = mb_strlen($text);
        $unicode = preg_match('/[^\x00-\x7F]/', $text) === 1;
        $single = $unicode ? 70 : 160;
        $multi = $unicode ? 67 : 153;

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }

    // ------------------------------------------------------------------ campaigns

    /**
     * @param  array<string, mixed>  $input  name, body, and the audience filters
     *
     * @throws StockException
     */
    public function create(array $input, User $by): SmsCampaign
    {
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 80);
        $body = trim((string) ($input['body'] ?? ''));

        if (mb_strlen($name) < 2) {
            throw new StockException('Give the campaign a name so you can find it later.');
        }

        if (mb_strlen($body) < 5) {
            throw new StockException('Write the message.');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            throw new StockException(__('The message is too long. Keep it under {n} letters.', ['n' => self::MAX_BODY]));
        }

        $filters = $this->filters($input);
        $count = $this->people($filters)->count();

        if ($count === 0) {
            throw new StockException('Nobody matches that. Widen who it is for.');
        }

        $campaign = SmsCampaign::create([
            'name' => $name, 'body' => $body, 'audience' => $filters, 'audience_label' => $this->label($filters), 'status' => 'draft', 'recipients' => $count, 'created_by' => $by->id,
        ]);
        $this->activity->record('sms.campaign_created', $campaign, ['recipients' => $count], $by);

        return $campaign;
    }

    /**
     * One real message to the owner's own number, with the same footer, so they see exactly what people will read.
     *
     * @throws StockException
     */
    public function test(SmsCampaign $campaign, string $phone, User $by): void
    {
        if (! preg_match('/^01[3-9]\d{8}$/', $phone)) {
            throw new StockException('Write a Bangladesh mobile number, like 01712345678.');
        }

        try {
            $this->gateway->send($phone, $campaign->body.$this->footer());
        } catch (\Throwable) {
            throw new StockException('The text service did not accept it. Check Connections.');
        }

        $this->activity->record('sms.campaign_tested', $campaign, [], $by);
    }

    /**
     * Send it. Everyone who matches now is written down first, then the texts go out a few a second.
     *
     * @throws StockException
     */
    public function start(SmsCampaign $campaign, User $by): SmsCampaign
    {
        return DB::transaction(function () use ($campaign, $by) {
            $campaign = SmsCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if ($campaign->status !== 'draft') {
                throw new StockException('This campaign was already sent or stopped.');
            }

            $people = $this->people($this->filters((array) $campaign->audience));

            if ($people->isEmpty()) {
                throw new StockException('Nobody matches that now.');
            }

            $left = $this->dailyLimit() - $this->sentToday();

            if ($this->dailyLimit() > 0 && $people->count() > $left) {
                throw new StockException(__('This would send {n} texts, but only {left} are left for today (the limit is {limit}). Narrow who it is for, or raise the limit in Settings.', ['n' => $people->count(), 'left' => max(0, $left), 'limit' => $this->dailyLimit()]));
            }

            foreach ($people->chunk(500) as $chunk) {
                SmsRecipient::insert($chunk->map(fn ($p) => ['campaign_id' => $campaign->id, 'user_id' => $p['id'], 'phone' => $p['phone'], 'status' => 'queued'])->all());
            }

            $campaign->update(['status' => 'sending', 'recipients' => $people->count(), 'started_at' => now()]);
            $this->activity->record('sms.campaign_started', $campaign, ['recipients' => $people->count()], $by);

            $rows = $campaign->people()->orderBy('id')->pluck('id');
            DB::afterCommit(function () use ($rows) {
                foreach ($rows as $i => $id) {
                    SendCampaignSms::dispatch($id)->delay(now()->addSeconds(intdiv($i, $this->perSecond())));
                }
            });

            return $campaign;
        });
    }

    /** Called by the queue for one person. A person who stopped promotions in the meantime is skipped. */
    public function deliver(int $recipientId): void
    {
        $r = SmsRecipient::find($recipientId);

        if ($r === null || $r->status !== 'queued') {
            return;
        }

        $campaign = SmsCampaign::find($r->campaign_id);

        if ($campaign === null || $campaign->status !== 'sending') {
            return;
        }

        $user = $r->user_id ? User::find($r->user_id) : null;

        if ($user && $user->marketing_optout_at !== null) {
            $r->update(['status' => 'failed', 'note' => 'Asked to stop']);
        } else {
            try {
                $this->gateway->send($r->phone, $campaign->body.$this->footer($user));
                $r->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (\Throwable $e) {
                $r->update(['status' => 'failed', 'note' => mb_substr(class_basename($e), 0, 100)]);
            }
        }

        $this->finishIfDone($campaign);
    }

    /** A stop button for a campaign that is still going out: what has not left is not sent. */
    public function cancel(SmsCampaign $campaign, User $by): void
    {
        if (! in_array($campaign->status, ['draft', 'sending'], true)) {
            throw new StockException('This campaign is already finished.');
        }

        $campaign->people()->where('status', 'queued')->update(['status' => 'failed', 'note' => 'Stopped']);
        $campaign->update(['status' => 'cancelled', 'finished_at' => now()]);
        $this->activity->record('sms.campaign_cancelled', $campaign, [], $by);
    }

    private function finishIfDone(SmsCampaign $campaign): void
    {
        if (! $campaign->people()->where('status', 'queued')->exists()) {
            $campaign->update(['status' => 'done', 'finished_at' => now()]);
        }
    }

    /** @return array{queued: int, sent: int, failed: int} */
    public function progress(SmsCampaign $campaign): array
    {
        $by = $campaign->people()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return ['queued' => (int) ($by['queued'] ?? 0), 'sent' => (int) ($by['sent'] ?? 0), 'failed' => (int) ($by['failed'] ?? 0)];
    }

    /** Texts promised for today, so the daily limit holds across campaigns. */
    public function sentToday(): int
    {
        return (int) SmsCampaign::where('started_at', '>=', now()->startOfDay())->whereIn('status', ['sending', 'done', 'cancelled'])->sum('recipients');
    }

    // ------------------------------------------------------------------ stopping

    /** The link in a message. The token is worked out from the person's number in our own key, so it cannot be guessed. */
    public function stop(int $userId, string $token): bool
    {
        $user = User::find($userId);

        if ($user === null || ! hash_equals($this->token($userId), $token)) {
            return false;
        }

        if ($user->marketing_optout_at === null) {
            $user->forceFill(['marketing_optout_at' => now()])->save();
            ActivityLog::create(['actor_id' => null, 'action' => 'sms.optout', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id, 'changes' => [], 'ip' => request()->ip()]);
        }

        return true;
    }

    /** The owner can put someone back, when they asked to. */
    public function resume(User $user, User $by): void
    {
        $user->forceFill(['marketing_optout_at' => null])->save();
        $this->activity->record('sms.optin', $user, [], $by);
    }
}
