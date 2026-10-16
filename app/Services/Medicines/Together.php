<?php

namespace App\Services\Medicines;

use App\Models\Medicine;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "People who bought this also took this" (spec ch 13, product page). Worked out from finished memos of the last six months: what
 * was on the same memo. Nothing shows until enough different memos agree (a setting, 5 by default), so no single buyer's basket is ever
 * visible, and a controlled medicine is never suggested.
 */
class Together
{
    public function minimum(): int
    {
        return max(2, (int) Setting::read('catalog.together_min', 5));
    }

    /** @return Collection<int, Medicine> */
    public function for(Medicine $medicine, int $limit = 3): Collection
    {
        $ids = Cache::remember("together:{$medicine->id}:{$limit}:{$this->minimum()}", 3600, fn () => $this->ids($medicine, $limit));

        if ($ids === []) {
            return collect();
        }

        return Medicine::query()->active()->with('company:id,name,logo_url,logo_path')->whereIn('id', $ids)->get()
            ->sortBy(fn (Medicine $m) => array_search($m->id, $ids, true))->values();
    }

    /** @return list<int> */
    private function ids(Medicine $medicine, int $limit): array
    {
        return DB::table('invoice_lines as a')
            ->join('invoice_lines as b', fn ($j) => $j->on('a.invoice_id', '=', 'b.invoice_id')->whereColumn('b.medicine_id', '!=', 'a.medicine_id'))
            ->join('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->join('medicines as m', 'm.id', '=', 'b.medicine_id')
            ->where('a.medicine_id', $medicine->id)
            ->where('i.status', 'final')->where('i.finalised_at', '>=', now()->subMonths(6))
            ->whereNull('a.removed_at')->whereNull('b.removed_at')
            ->where('a.is_outside', false)->where('b.is_outside', false)
            ->where('m.is_active', true)->where('m.is_controlled', false)->whereNull('m.deleted_at')
            ->groupBy('b.medicine_id')
            ->havingRaw('count(distinct a.invoice_id) >= ?', [$this->minimum()])
            ->orderByRaw('count(distinct a.invoice_id) desc')->orderBy('b.medicine_id')
            ->limit($limit)->pluck('b.medicine_id')->map(fn ($id) => (int) $id)->all();
    }
}
