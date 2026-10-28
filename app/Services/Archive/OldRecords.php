<?php

namespace App\Services\Archive;

use App\Models\ActivityLog;
use App\Models\ArchivedRecord;
use App\Models\LoginEvent;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Old data stored separately (spec ch 28, note 11): searchable, but not burdening daily work. Each night, records older than the age the owner set (months, 0 for
 * never) move from the busy tables to one archive table, whole, with the words that describe them kept for searching. Only what daily work has stopped reading
 * moves: the staff activity log, sign-in records, and notices a person has already read. Orders, memos, money and stock are never moved.
 */
class OldRecords
{
    public const SOURCES = ['activity', 'login', 'notice'];

    private const CHUNK = 500;

    public function months(): int
    {
        return max(0, (int) Setting::read('archive.after_months', 12));
    }

    /** The moment before which a record counts as old, or nothing when archiving is off. */
    public function cutoff(): ?Carbon
    {
        return $this->months() > 0 ? now()->subMonths($this->months()) : null;
    }

    /**
     * Move what is old. Safe to run again: a record already in the archive is not copied twice, and it leaves the busy table either way.
     *
     * @return array<string, int> how many of each kind moved
     */
    public function run(): array
    {
        $cutoff = $this->cutoff();
        $moved = array_fill_keys(self::SOURCES, 0);

        if ($cutoff === null) {
            return $moved;
        }

        $moved['activity'] = $this->move(ActivityLog::query()->where('created_at', '<', $cutoff), 'activity', fn (ActivityLog $l, Collection $names) => [
            $l->created_at, trim(($names[$l->actor_id] ?? 'system').' '.$l->action.' '.class_basename((string) $l->subject_type).($l->subject_id ? ' #'.$l->subject_id : '').' '.json_encode($l->changes, JSON_UNESCAPED_UNICODE)),
        ]);
        $moved['login'] = $this->move(LoginEvent::query()->where('created_at', '<', $cutoff), 'login', fn (LoginEvent $e, Collection $names) => [
            $e->created_at, trim($e->outcome.' '.($e->email ?? '').' '.$e->ip.' '.($e->reason ?? '').($e->is_suspicious ? ' suspicious' : '')),
        ]);
        $moved['notice'] = $this->move(Notice::query()->whereNotNull('read_at')->where('created_at', '<', $cutoff), 'notice', fn (Notice $n, Collection $names) => [
            $n->created_at, trim(($names[$n->user_id] ?? '').' '.$n->kind.' '.$n->body),
        ]);

        return $moved;
    }

    /**
     * @param  Builder<Model>  $old
     * @param  callable(Model, Collection<int, string>): array{0: Carbon, 1: string}  $describe
     */
    private function move(Builder $old, string $source, callable $describe): int
    {
        $count = 0;

        while (true) {
            $rows = (clone $old)->orderBy('id')->limit(self::CHUNK)->get();

            if ($rows->isEmpty()) {
                return $count;
            }

            $names = User::whereIn('id', $rows->flatMap(fn ($r) => array_filter([$r->actor_id ?? null, $r->user_id ?? null]))->unique())->pluck('name', 'id');

            DB::transaction(function () use ($rows, $source, $describe, $names, $old, &$count) {
                $now = now();

                foreach ($rows as $row) {
                    [$at, $summary] = $describe($row, $names);
                    ArchivedRecord::firstOrCreate(['source' => $source, 'source_id' => $row->getKey()], [
                        'occurred_at' => $at, 'summary' => mb_substr($summary, 0, 4000), 'payload' => $row->getAttributes(), 'archived_at' => $now,
                    ]);
                }

                $old->getModel()->newQuery()->whereKey($rows->modelKeys())->delete();
                $count += $rows->count();
            });
        }
    }

    /**
     * Search the archive by words, kind and dates.
     *
     * @return LengthAwarePaginator<int, ArchivedRecord>
     */
    public function search(?string $words, ?string $source, ?string $from, ?string $to, int $perPage = 40): LengthAwarePaginator
    {
        $words = trim((string) $words);

        return ArchivedRecord::query()
            ->when(in_array($source, self::SOURCES, true), fn ($q) => $q->where('source', $source))
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $this->day($from)?->startOfDay() ?? '1970-01-01'))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $this->day($to)?->endOfDay() ?? '2999-12-31'))
            ->when($words !== '', function ($q) use ($words) {
                // Every word must appear, in any order.
                foreach (preg_split('/\s+/u', $words, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $q->where('summary', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            })
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    /** @return array<string, int> how many of each kind are in the archive */
    public function counts(): array
    {
        $found = ArchivedRecord::query()->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source')->all();

        return array_merge(array_fill_keys(self::SOURCES, 0), array_map('intval', $found));
    }

    private function day(string $text): ?Carbon
    {
        try {
            return Carbon::parse($text);
        } catch (\Throwable) {
            return null;
        }
    }
}
