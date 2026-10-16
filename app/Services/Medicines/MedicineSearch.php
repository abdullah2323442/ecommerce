<?php

namespace App\Services\Medicines;

use App\Models\Company;
use App\Models\Medicine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Medicine search (spec ch 11 / 6.10): finds by brand or generic name, tolerates misspellings,
 * accepts Bengali script, ranks exact and prefix matches first, and lists same-generic alternatives.
 *
 * Stock is never part of the result: paikari buyers must not see it (spec G9).
 */
class MedicineSearch
{
    public const MAX_RESULTS = 30;

    /** Fuzzy pass only looks at names sharing this many leading characters. */
    private const FUZZY_PREFIX = 1;

    private const FUZZY_CANDIDATES = 8000;

    /** Upper bound for the full results page; the page says "more than N" when it is reached. */
    public const MAX_PAGE_RESULTS = 200;

    /**
     * @param  array{form?: ?string, company_id?: ?int, generic?: ?string}  $filters  narrow the search on the server
     * @return Collection<int, Medicine>
     */
    public function search(string $query, int $limit = self::MAX_RESULTS, array $filters = []): Collection
    {
        $needle = $this->normalize($query);
        if (mb_strlen($needle) < 2) {
            return collect();
        }

        $hits = $this->exactTokens($needle, $limit, $filters);

        if ($hits->count() < min(5, $limit)) {
            $hits = $hits->merge($this->fuzzy($needle, $limit, $filters))->unique('id');
        }

        return $hits
            ->map(fn (Medicine $m) => [$m, $this->rank($m, $needle)])
            ->sort(fn (array $a, array $b) => $a[1] <=> $b[1])
            ->take($limit)
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }

    /**
     * Other brands with the same generic name, cheapest first (spec: generic alternatives).
     *
     * @return Collection<int, Medicine>
     */
    public function alternatives(Medicine $medicine, int $limit = 10): Collection
    {
        if (! $medicine->generic_name) {
            return collect();
        }

        return Medicine::query()
            ->active()
            ->with('company:id,name')
            ->where('generic_name', $medicine->generic_name)
            ->where('strength', $medicine->strength)
            ->where('form', $medicine->form)
            ->whereKeyNot($medicine->getKey())
            ->orderBy('mrp_piece')
            ->limit($limit)
            ->get();
    }

    public function normalize(string $query): string
    {
        $query = trim(mb_strtolower($query));

        if (BanglaTransliterator::containsBangla($query)) {
            $query = BanglaTransliterator::toLatin($query);
        }

        return trim(preg_replace('/\s+/u', ' ', $query));
    }

    /**
     * Generic names matching the query, with how many active medicines carry each. Prefix matches first.
     *
     * @return list<array{name: string, count: int}>
     */
    public function generics(string $query, int $limit = 3): array
    {
        $needle = $this->normalize($query);
        if (mb_strlen($needle) < 2) {
            return [];
        }

        return Medicine::query()->active()
            ->whereNotNull('generic_name')
            ->where('generic_name', 'like', '%'.$this->escapeLike($needle).'%')
            ->selectRaw('generic_name, count(*) as total, case when lower(generic_name) like ? then 0 else 1 end as prefix_rank', [$this->escapeLike($needle).'%'])
            ->groupBy('generic_name')
            ->orderBy('prefix_rank')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['name' => $r->generic_name, 'count' => (int) $r->total])
            ->all();
    }

    /**
     * Companies whose name matches the query and that have active medicines.
     *
     * @return list<array{id: int, name: string, logo: ?string, count: int}>
     */
    public function companies(string $query, int $limit = 3): array
    {
        $needle = $this->normalize($query);
        if (mb_strlen($needle) < 2) {
            return [];
        }

        return Company::query()
            ->where('is_active', true)
            ->where('name', 'like', '%'.$this->escapeLike($needle).'%')
            ->withCount(['medicines as medicines_count' => fn ($m) => $m->active()])
            ->having('medicines_count', '>', 0)
            ->orderByDesc('medicines_count')
            ->limit($limit)
            ->get(['id', 'name', 'logo_url', 'logo_path'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name, 'logo' => $c->logo, 'count' => (int) $c->medicines_count])
            ->all();
    }

    /**
     * @param  array{form?: ?string, company_id?: ?int, generic?: ?string}  $filters
     * @return Collection<int, Medicine>
     */
    private function exactTokens(string $needle, int $limit, array $filters = []): Collection
    {
        $tokens = array_values(array_filter(explode(' ', $needle), fn ($t) => $t !== ''));

        return $this->base($filters)
            ->where(function (Builder $q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.$this->escapeLike($token).'%';
                    $q->where(fn (Builder $w) => $w
                        ->where('brand_name', 'like', $like)
                        ->orWhere('generic_name', 'like', $like)
                        ->orWhere('strength', 'like', $like));
                }
            })
            ->limit($limit * 4)
            ->get();
    }

    /**
     * @param  array{form?: ?string, company_id?: ?int, generic?: ?string}  $filters
     * @return Collection<int, Medicine>
     */
    private function fuzzy(string $needle, int $limit, array $filters = []): Collection
    {
        $first = mb_substr($needle, 0, self::FUZZY_PREFIX);
        $length = mb_strlen($needle);
        $maxDistance = $length <= 4 ? 1 : 2;

        // Compare distinct brand names only (25k rows collapse to far fewer), narrowed to the
        // same first letter and a length window, then fetch the medicines for the close ones.
        $names = Medicine::query()
            ->active()
            ->where('brand_name', 'like', $this->escapeLike($first).'%')
            ->whereRaw('LENGTH(brand_name) BETWEEN ? AND ?', [max(1, $length - $maxDistance), $length + $maxDistance])
            ->distinct()
            ->limit(self::FUZZY_CANDIDATES)
            ->pluck('brand_name')
            ->filter(fn (string $name) => $this->distance($needle, mb_strtolower($name)) <= $maxDistance)
            ->values();

        if ($names->isEmpty()) {
            return collect();
        }

        return $this->base($filters)->whereIn('brand_name', $names->all())->limit($limit * 4)->get();
    }

    /**
     * Sort key, lower is better: [tier, edit distance to the brand, brand length, brand, strength].
     * Tiers: exact brand, brand prefix, every word of the query prefixes a brand word,
     * brand contains the query, generic contains the query, then fuzzy matches.
     *
     * @return array{int, int, int, string, string}
     */
    private function rank(Medicine $m, string $needle): array
    {
        $brand = mb_strtolower($m->brand_name);
        $tier = match (true) {
            $brand === $needle => 0,
            str_starts_with($brand, $needle) => 1,
            $this->wordsPrefixMatch($needle, $brand) => 2,
            str_contains($brand, $needle) => 3,
            $m->generic_name && str_contains(mb_strtolower($m->generic_name), $needle) => 4,
            default => 5,
        };

        return [$tier, $this->distance($needle, $brand), mb_strlen($brand), $brand, (string) $m->strength];
    }

    /** True when every query word is the start of some word in the brand ("ace plus" ~ "Ace Plus 500"). */
    private function wordsPrefixMatch(string $needle, string $brand): bool
    {
        $brandWords = preg_split('/[^a-z0-9]+/u', $brand, -1, PREG_SPLIT_NO_EMPTY);

        foreach (explode(' ', $needle) as $word) {
            $found = false;
            foreach ($brandWords as $bw) {
                if (str_starts_with($bw, $word)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function distance(string $a, string $b): int
    {
        // levenshtein() is byte-based and capped at 255 chars; names are short Latin text.
        return levenshtein(substr($a, 0, 60), substr($b, 0, 60));
    }

    /** @param  array{form?: ?string, company_id?: ?int, generic?: ?string}  $filters */
    private function base(array $filters = []): Builder
    {
        return Medicine::query()->active()->with('company:id,name,logo_url,logo_path')
            ->when($filters['form'] ?? null, fn (Builder $q, string $form) => $q->where('form', $form))
            ->when($filters['company_id'] ?? null, fn (Builder $q, int $id) => $q->where('company_id', $id))
            ->when($filters['generic'] ?? null, fn (Builder $q, string $generic) => $q->where('generic_name', $generic));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
