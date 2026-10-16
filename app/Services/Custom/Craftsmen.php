<?php

namespace App\Services\Custom;

use App\Enums\AccountType;
use App\Models\CraftsmanPayment;
use App\Models\CustomMember;
use App\Models\CustomMove;
use App\Models\CustomOrder;
use App\Models\CustomProgress;
use App\Models\Notice;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Media\ImageCompressor;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Our own craftsmen and the jobs they do (spec ch 16.4, 16.7). We open their accounts and assign each job; one craftsman is mainly
 * responsible and may shift it or share it; wages and progress are the same percentage, each entry with a photo; a job below 100% is not
 * complete. They never see what the buyer pays, our profit, the buyer's phone, other craftsmen's jobs or stock.
 */
class Craftsmen
{
    public function __construct(private CustomOrders $orders, private Messenger $messages, private ImageCompressor $images, private ActivityLogger $activity) {}

    /** No update for this many days and we are told (spec ch 16.7). */
    public const STALLED_DAYS = 3;

    // ------------------------------------------------------------------ accounts

    public function open(array $data, User $by): User
    {
        return DB::transaction(function () use ($data, $by) {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'], 'phone' => ($data['phone'] ?? null) ?: null,
                'email' => ($data['email'] ?? null) ?: 'craftsman-'.Str::lower(Str::random(8)).'@phone.invalid', 'password' => Hash::make($data['password']),
                'account_type' => AccountType::Craftsman, 'email_verified_at' => now(),
            ])->save();
            $this->activity->record('craftsman.opened', $user, [], $by);

            return $user;
        });
    }

    /** @return Collection<int, User> */
    public function all(): Collection
    {
        return User::where('account_type', AccountType::Craftsman)->orderBy('name')->get();
    }

    /** How many jobs they are on that are not finished (spec ch 16.7: checked before assigning). */
    public function activeJobs(User $craftsman): int
    {
        return CustomMember::where('craftsman_id', $craftsman->id)->where('active', true)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['confirmed', 'making', 'trial']))->count();
    }

    // ------------------------------------------------------------------ wages first

    /** We show a job to one craftsman so they can say what the wage should be. They see the job, never the price. */
    public function askWage(CustomOrder $order, User $by, User $craftsman): CustomOrder
    {
        $this->mustBeCraftsman($craftsman);

        if (! in_array($order->status, ['requested', 'priced', 'confirmed'], true)) {
            throw new StockException('The wage is agreed before the work starts.');
        }

        $order->update(['wage_asked_id' => $craftsman->id, 'wage_proposed' => null, 'wage_proposal_note' => null]);
        $this->orders->log($order, $by, 'wage_asked', 'We asked for a wage.');
        $this->messages->send($craftsman, 'craftsman_wage_ask', ['name' => $order->number()], '/craftsman/jobs/'.$order->id);

        return $order->fresh();
    }

    public function propose(CustomOrder $order, User $craftsman, float $amount, ?string $note = null): CustomOrder
    {
        if ($order->wage_asked_id !== $craftsman->id) {
            throw new StockException('You were not asked about this job.');
        }

        if ($amount <= 0 || $amount > 100000000) {
            throw new StockException('The wage must be more than zero.');
        }

        $order->update(['wage_proposed' => round($amount, 2), 'wage_proposal_note' => $note ? mb_substr(trim($note), 0, 300) : null]);
        $this->notify('custom_proposal', $craftsman, 'proposed a wage for '.$order->number());

        return $order->fresh();
    }

    /** We finalize the wage: the craftsman's figure or our own. */
    public function setWages(CustomOrder $order, User $by, float $amount): CustomOrder
    {
        if (! $order->isOpen() || in_array($order->status, ['done'], true)) {
            throw new StockException('The wage can no longer be changed.');
        }

        if ($amount < 0) {
            throw new StockException('The wage cannot be less than zero.');
        }

        $order->update(['wages_total' => round($amount, 2)]);
        $this->orders->log($order, $by, 'wages_set', 'The wage is set.');

        return $order->fresh();
    }

    // ------------------------------------------------------------------ assign, shift, share

    /** @throws StockException */
    public function assign(CustomOrder $order, User $by, User $craftsman): CustomOrder
    {
        $this->mustBeCraftsman($craftsman);

        // If the wages are not finalized the job does not go to the craftsman at all (spec ch 16.7).
        if ($order->wages_total === null) {
            throw new StockException('Set the wages before you give the job to a craftsman.');
        }

        if (! in_array($order->status, ['confirmed', 'making', 'trial'], true)) {
            throw new StockException('A job is given out once the buyer has confirmed it.');
        }

        $this->makeMain($order, $craftsman, $by, 'assign', $order->craftsman_id);
        $this->messages->send($craftsman, 'craftsman_job', ['name' => $order->number()], '/craftsman/jobs/'.$order->id);

        return $order->fresh();
    }

    /** The one mainly responsible hands the job on: "Rahim took it, gave it to Karim". */
    public function shift(CustomOrder $order, User $main, User $to, ?string $note = null): CustomOrder
    {
        $this->mustBeMain($order, $main);
        $this->mustBeCraftsman($to);

        if ($to->id === $main->id) {
            throw new StockException('Pick someone else.');
        }

        $this->makeMain($order, $to, $main, 'shift', $main->id, $note);
        $this->messages->send($to, 'craftsman_job', ['name' => $order->number()], '/craftsman/jobs/'.$order->id);
        $this->notify('custom_shift', $main, 'gave '.$order->number().' to '.$to->name);

        return $order->fresh();
    }

    /** Take someone on as a share: they see the job and can be credited with a part of it. */
    public function share(CustomOrder $order, User $main, User $helper, ?string $note = null): CustomOrder
    {
        $this->mustBeMain($order, $main);
        $this->mustBeCraftsman($helper);

        $member = CustomMember::firstOrNew(['custom_order_id' => $order->id, 'craftsman_id' => $helper->id]);

        if ($member->exists && $member->active) {
            throw new StockException('They are already on this job.');
        }

        $member->fill(['role' => $member->role ?? 'helper', 'active' => true])->save();
        $this->move($order, 'share', $main->id, $helper->id, $main->id, $note);
        $this->messages->send($helper, 'craftsman_job', ['name' => $order->number()], '/craftsman/jobs/'.$order->id);

        return $order->fresh();
    }

    // ------------------------------------------------------------------ progress and wages

    /** Total done so far, 0 to 100. */
    public function done(CustomOrder $order): float
    {
        return round((float) CustomProgress::where('custom_order_id', $order->id)->sum('percent'), 2);
    }

    /**
     * The one mainly responsible enters what each person did. A photo comes with every entry so it can be checked; the total never passes 100.
     *
     * @throws StockException
     */
    public function record(CustomOrder $order, User $main, User $credited, float $percent, ?UploadedFile $photo, ?string $note = null): CustomProgress
    {
        $this->mustBeMain($order, $main);

        if (! in_array($order->status, ['making', 'trial'], true)) {
            throw new StockException('Progress is entered while the work is going on.');
        }

        if (! CustomMember::where('custom_order_id', $order->id)->where('craftsman_id', $credited->id)->exists()) {
            throw new StockException('That person is not on this job.');
        }

        if ($percent <= 0 || $percent > 100) {
            throw new StockException('The percentage is more than 0 and not more than 100.');
        }

        if ($photo === null) {
            throw new StockException('A photo of the work is needed with every update.');
        }

        if ($this->done($order) + $percent > 100.0001) {
            throw new StockException('That would be more than 100% in all.');
        }

        $entry = CustomProgress::create([
            'custom_order_id' => $order->id, 'craftsman_id' => $credited->id, 'entered_by' => $main->id, 'percent' => round($percent, 2),
            'photo_path' => $this->images->store($photo, 'custom-progress'), 'note' => $note ? mb_substr(trim($note), 0, 200) : null,
        ]);
        $order->update(['progress_nudged_at' => null, 'waiting_since' => null, 'waiting_note' => null]);
        $this->orders->log($order, $main, 'progress', 'The work is '.$this->done($order).'% done.');

        return $entry;
    }

    /** If the split is in dispute we can see it and fix it. */
    public function correct(CustomProgress $entry, User $by, float $percent, ?User $credited = null): CustomProgress
    {
        $order = $entry->order;
        $others = $this->done($order) - (float) $entry->percent;

        if ($percent <= 0 || $others + $percent > 100.0001) {
            throw new StockException('That would be more than 100% in all.');
        }

        $entry->update(['percent' => round($percent, 2), 'craftsman_id' => $credited?->id ?? $entry->craftsman_id]);
        $this->orders->log($order, $by, 'progress_fixed', 'We corrected the split.');

        return $entry->fresh();
    }

    /** The wage of one person on one job: the total wage by their part of the work. */
    public function wageOn(CustomOrder $order, User $craftsman): float
    {
        $part = (float) CustomProgress::where('custom_order_id', $order->id)->where('craftsman_id', $craftsman->id)->sum('percent');

        return round((float) ($order->wages_total ?? 0) * $part / 100, 2);
    }

    /** What the buyer sees: how far, never who. */
    public function percentFor(CustomOrder $order): int
    {
        return (int) round($this->done($order));
    }

    // ------------------------------------------------------------------ materials, time

    /** "Materials needed": the job shows Waiting, so the buyer knows the delay is not theirs. */
    public function materialsNeeded(CustomOrder $order, User $by, string $note): CustomOrder
    {
        $this->mustBeOn($order, $by);

        if (mb_strlen(trim($note)) < 3) {
            throw new StockException('Say what is needed.');
        }

        $order->update(['waiting_since' => now(), 'waiting_note' => mb_substr(trim($note), 0, 200)]);
        $this->orders->log($order, $by, 'waiting', 'Waiting for materials.');
        $this->notify('custom_waiting', $by, 'needs materials for '.$order->number().': '.trim($note));

        return $order->fresh();
    }

    public function materialsArrived(CustomOrder $order, User $by): CustomOrder
    {
        $order->update(['waiting_since' => null, 'waiting_note' => null]);
        $this->orders->log($order, $by, 'materials_arrived', 'The materials arrived.');

        return $order->fresh();
    }

    /** New time after a delay; the delay stays on the craftsman's record. */
    public function extend(CustomOrder $order, User $by, int $days): CustomOrder
    {
        if (! in_array($order->status, ['making', 'trial'], true) || $days < 1 || $days > 90) {
            throw new StockException('Give a number of days for work that is going on.');
        }

        $order->update(['due_on' => today()->addDays($days), 'late_notified_at' => null, 'delays' => $order->delays + ($order->due_on?->lt(today()) ? 1 : 0)]);
        $this->orders->log($order, $by, 'extended', 'We gave a new time.');

        return $order->fresh();
    }

    /**
     * Jobs past their time tell us and the buyer once; jobs with no update for three days tell us and the craftsman.
     *
     * @return array{late: int, stalled: int}
     */
    public function watch(): array
    {
        $late = 0;
        $stalled = 0;

        CustomOrder::whereIn('status', ['making', 'trial'])->whereNotNull('due_on')->where('due_on', '<', today())->whereNull('late_notified_at')->with(['buyer', 'craftsman'])->each(function (CustomOrder $o) use (&$late) {
            $o->update(['late_notified_at' => now(), 'delays' => $o->delays + 1]);
            $this->messages->send($o->buyer, 'custom_late', ['name' => $o->number()], '/custom/'.$o->public_token);
            $this->notify('custom_late', null, $o->number().' is past its time');
            $late++;
        });

        CustomOrder::whereIn('status', ['making', 'trial'])->whereNotNull('craftsman_id')->whereNull('waiting_since')->with('craftsman')->each(function (CustomOrder $o) use (&$stalled) {
            $last = CustomProgress::where('custom_order_id', $o->id)->max('created_at') ?? $o->started_at;
            $since = $o->progress_nudged_at ?? $last;

            if ($this->done($o) < 100 && $since !== null && now()->diffInDays($since, true) >= self::STALLED_DAYS) {
                $o->update(['progress_nudged_at' => now()]);
                if ($o->craftsman) {
                    $this->messages->send($o->craftsman, 'craftsman_reminder', ['name' => $o->number()], '/craftsman/jobs/'.$o->id);
                }
                $this->notify('custom_stalled', $o->craftsman, 'has no update for '.self::STALLED_DAYS.' days on '.$o->number());
                $stalled++;
            }
        });

        return ['late' => $late, 'stalled' => $stalled];
    }

    // ------------------------------------------------------------------ money

    /** The advance goes to the craftsman who starts the work: they need money to begin (spec ch 16.6). */
    public function giveAdvance(CustomOrder $order, User $by, float $amount): CraftsmanPayment
    {
        if ($order->craftsman_id === null) {
            throw new StockException('Give the job to a craftsman first.');
        }

        if ($amount <= 0 || $amount > (float) $order->advance_paid - (float) $order->advance_given + 0.001) {
            throw new StockException('That is more than the advance we have received.');
        }

        $payment = CraftsmanPayment::create(['craftsman_id' => $order->craftsman_id, 'custom_order_id' => $order->id, 'kind' => 'advance', 'amount' => round($amount, 2), 'paid_on' => today(), 'paid_by' => $by->id]);
        $order->update(['advance_given' => round((float) $order->advance_given + $amount, 2)]);
        $this->orders->log($order, $by, 'advance_given', 'The advance went to the maker.');

        return $payment;
    }

    public function pay(User $craftsman, User $by, float $amount, ?string $note = null): CraftsmanPayment
    {
        $this->mustBeCraftsman($craftsman);

        if ($amount <= 0 || $amount > 100000000) {
            throw new StockException('Check the amount.');
        }

        return CraftsmanPayment::create(['craftsman_id' => $craftsman->id, 'kind' => 'wages', 'amount' => round($amount, 2), 'note' => $note ? mb_substr(trim($note), 0, 200) : null, 'paid_on' => today(), 'paid_by' => $by->id]);
    }

    /**
     * Owed and paid, separately; and what was earned this month, so month end shows who is owed how much at a glance (spec ch 16.7).
     *
     * @return array{earned: float, paid: float, owed: float, month: float, delays: int}
     */
    public function ledger(User $craftsman, ?string $month = null): array
    {
        $month ??= now()->format('Y-m');
        $earned = 0.0;
        $thisMonth = 0.0;

        CustomProgress::where('craftsman_id', $craftsman->id)->with('order:id,wages_total')->get()->each(function (CustomProgress $p) use (&$earned, &$thisMonth, $month) {
            $wage = (float) ($p->order->wages_total ?? 0) * (float) $p->percent / 100;
            $earned += $wage;
            if ($p->created_at->format('Y-m') === $month) {
                $thisMonth += $wage;
            }
        });

        $paid = (float) CraftsmanPayment::where('craftsman_id', $craftsman->id)->sum('amount');

        return [
            'earned' => round($earned, 2), 'paid' => round($paid, 2), 'owed' => round($earned - $paid, 2), 'month' => round($thisMonth, 2),
            'delays' => (int) CustomOrder::where('craftsman_id', $craftsman->id)->sum('delays'),
        ];
    }

    // ------------------------------------------------------------------ what a craftsman may see

    /**
     * Measurements and the job, never the price, the buyer or what we make. A helper sees only their own wage.
     *
     * @return array<string, mixed>
     */
    public function jobFor(CustomOrder $o, User $craftsman): array
    {
        $isMain = $this->isMain($o, $craftsman);
        $members = CustomMember::where('custom_order_id', $o->id)->with('craftsman:id,name')->get();

        return [
            'id' => $o->id, 'number' => $o->number(), 'kind' => $o->kind, 'status' => $o->status, 'material' => $o->material_name, 'fabric_by' => $o->fabric_by, 'items' => $o->items, 'details' => $o->details,
            'note' => $o->note, 'urgent' => $o->urgent, 'has_photo' => $o->photo_path !== null, 'due_on' => $o->due_on?->toDateString(), 'days' => $o->days_needed,
            'is_main' => $isMain, 'done' => $this->done($o), 'waiting' => $o->waiting_note,
            'wage_asked' => $o->wage_asked_id === $craftsman->id && $o->craftsman_id === null, 'wage_proposed' => $o->wage_asked_id === $craftsman->id && $o->wage_proposed !== null ? (float) $o->wage_proposed : null,
            // The one mainly responsible sees the whole wage, to split it; anyone else sees only their own.
            'wage_total' => $isMain && $o->wages_total !== null ? (float) $o->wages_total : null, 'my_wage' => $this->wageOn($o, $craftsman),
            'team' => $members->where('active', true)->map(fn (CustomMember $m) => ['id' => $m->craftsman_id, 'name' => $m->craftsman->name, 'role' => $m->role])->values(),
            'entries' => CustomProgress::where('custom_order_id', $o->id)->with('craftsman:id,name')->orderBy('id')->get()->map(fn (CustomProgress $p) => [
                'id' => $p->id, 'who' => $p->craftsman->name, 'percent' => (float) $p->percent, 'note' => $p->note, 'at' => $p->created_at->toIso8601String(),
            ])->values(),
            'moves' => $o->moves()->with(['from:id,name', 'to:id,name'])->get()->map(fn (CustomMove $m) => ['kind' => $m->kind, 'from' => $m->from?->name, 'to' => $m->to?->name, 'at' => $m->created_at->toIso8601String()])->values(),
        ];
    }

    /** May this craftsman see this job at all? On it, or asked about its wage. */
    public function canSee(CustomOrder $o, User $craftsman): bool
    {
        return CustomMember::where('custom_order_id', $o->id)->where('craftsman_id', $craftsman->id)->where('active', true)->exists()
            || ($o->wage_asked_id === $craftsman->id && $o->craftsman_id === null && $o->isOpen());
    }

    public function isMain(CustomOrder $o, User $craftsman): bool
    {
        return $o->craftsman_id === $craftsman->id;
    }

    // ------------------------------------------------------------------ helpers

    private function makeMain(CustomOrder $order, User $to, User $by, string $kind, ?int $fromId, ?string $note = null): void
    {
        DB::transaction(function () use ($order, $to, $kind, $fromId, $by, $note) {
            // Whoever had it leaves the job, and keeps what they earned; the new one continues from where it stands.
            if ($fromId !== null && $fromId !== $to->id) {
                CustomMember::where('custom_order_id', $order->id)->where('craftsman_id', $fromId)->update(['active' => false, 'role' => 'helper']);
            }

            CustomMember::updateOrCreate(['custom_order_id' => $order->id, 'craftsman_id' => $to->id], ['role' => 'main', 'active' => true]);
            $order->update(['craftsman_id' => $to->id]);
            $this->move($order, $kind, $fromId, $to->id, $by->id, $note);
        });
    }

    private function move(CustomOrder $order, string $kind, ?int $from, int $to, int $by, ?string $note): void
    {
        CustomMove::create(['custom_order_id' => $order->id, 'kind' => $kind, 'from_id' => $from, 'to_id' => $to, 'by_id' => $by, 'note' => $note ? mb_substr(trim($note), 0, 200) : null]);
        $this->orders->log($order, User::find($by), $kind, $kind === 'assign' ? 'Given to a craftsman.' : ($kind === 'shift' ? 'The job was handed on.' : 'Someone was taken on as a share.'));
    }

    private function mustBeCraftsman(User $user): void
    {
        if ($user->account_type !== AccountType::Craftsman || $user->isSuspended()) {
            throw new StockException('Pick one of our craftsmen.');
        }
    }

    private function mustBeMain(CustomOrder $order, User $user): void
    {
        if (! $this->isMain($order, $user)) {
            throw new StockException('Only the one mainly responsible can do this.');
        }
    }

    private function mustBeOn(CustomOrder $order, User $user): void
    {
        if (! CustomMember::where('custom_order_id', $order->id)->where('craftsman_id', $user->id)->where('active', true)->exists()) {
            throw new StockException('This is not your job.');
        }
    }

    /** A line for those who run the orders. */
    private function notify(string $kind, ?User $who, string $what): void
    {
        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => $kind, 'body' => mb_substr(trim(($who?->name ?? '').' '.$what), 0, 290)]);
        }
    }
}
