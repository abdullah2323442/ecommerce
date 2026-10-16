<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Medicine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the existing `pharmacy` medicine catalogue (companies + medicines) into this app's
 * schema and links each medicine to its image in public/medicine_images.
 *
 * Image files are named `med_<n>_<name-slug>.webp`. <n> is NOT the legacy medicine id; it is a
 * sequence number from an older export. Within one brand name, files in <n> order line up with
 * the medicines in id order (checked by eye on Abaclor). So we match per name-slug group:
 *   - files == medicines that have a source photo  -> pair in order with those medicines
 *   - files == all medicines of that name          -> pair in order with all of them
 *   - anything else is left without a local image and keeps its remote source URL.
 *
 * Safe to re-run (idempotent on legacy_id).
 */
class ImportLegacyMedicines extends Command
{
    protected $signature = 'medicines:import
        {--images=medicine_images : Medicine image folder, relative to public/}
        {--logos=company_logos : Company logo folder, relative to public/}
        {--dry-run : Analyse and report only, write nothing}';

    protected $description = 'Import companies and medicines from the legacy pharmacy database and link local images';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $legacy = DB::connection('legacy');
        $dry = (bool) $this->option('dry-run');

        $this->info('Companies…');
        $companyMap = $this->importCompanies($legacy, $dry, $this->option('logos'));

        $this->info('Matching images…');
        $imageFor = $this->matchImages($legacy, $this->option('images'));

        $this->info('Medicines…');
        $count = 0;
        $withLocal = 0;
        $remoteOnly = 0;
        $now = now();
        $rows = [];

        $flush = function () use (&$rows, &$count, $dry) {
            if ($rows && ! $dry) {
                Medicine::upsert($rows, ['legacy_id'], [
                    'company_id', 'brand_name', 'generic_name', 'strength', 'form', 'volume',
                    'packaging_type', 'strips_per_box', 'pieces_per_strip',
                    'mrp_piece', 'mrp_strip', 'mrp_box', 'is_active',
                    'image_path', 'image_source_url', 'source_url', 'updated_at',
                ]);
            }
            $count += count($rows);
            $rows = [];
        };

        foreach ($legacy->table('medicines')->whereNull('deleted_at')->orderBy('id')->lazyById(self::CHUNK) as $m) {
            $meta = json_decode($m->box_price ?? '', true) ?: [];
            $isBox = $m->packaging_type === 'box';
            $image = $imageFor[$m->id] ?? null;
            $withLocal += $image ? 1 : 0;
            $remoteOnly += (! $image && $m->photo) ? 1 : 0;

            $rows[] = [
                'legacy_id' => $m->id,
                'company_id' => $companyMap[$m->company_id] ?? null,
                'brand_name' => $m->name,
                'generic_name' => $this->clean($meta['generic_name'] ?? null),
                'strength' => $this->clean($m->strength),
                'form' => $this->clean($m->type),
                'volume' => $this->clean($meta['volume'] ?? null),
                'packaging_type' => $m->packaging_type ?: 'box',
                'strips_per_box' => $m->strips_per_box,
                'pieces_per_strip' => $m->pieces_per_strip,
                'mrp_piece' => $this->money($meta['price'] ?? null),
                'mrp_strip' => $this->money($meta['strip_price'] ?? null),
                // For non-box packs `pack_price_value` is the bottle volume, not a price.
                'mrp_box' => $isBox ? $this->money($meta['pack_price_value'] ?? null) : null,
                'prescription_required' => false,
                'is_cold_chain' => false,
                'is_controlled' => false,
                'is_active' => (bool) $m->is_active,
                'image_path' => $image,
                'image_source_url' => $this->clean($m->photo),
                'source_url' => $this->clean($meta['medex_url'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();

        $this->newLine();
        $this->table(['Result', 'Count'], [
            ['Companies', count($companyMap)],
            ['Medicines '.($dry ? '(would import)' : 'imported'), $count],
            ['…with a local image', $withLocal],
            ['…remote image only (no local file matched)', $remoteOnly],
        ]);

        return self::SUCCESS;
    }

    /** @return array<int,int> legacy company id => new company id */
    private function importCompanies($legacy, bool $dry, string $logoFolder): array
    {
        $now = now();
        $logos = $this->matchLogos($logoFolder);
        $rows = $legacy->table('companies')->whereNull('deleted_at')->orderBy('id')->get()->map(fn ($c) => [
            'legacy_id' => $c->id,
            'name' => $c->name,
            'logo_url' => $this->clean($c->logo),
            'logo_path' => $logos[$this->slug($c->name)] ?? null,
            'is_active' => (bool) $c->is_active,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if (! $dry) {
            Company::upsert($rows, ['legacy_id'], ['name', 'logo_url', 'logo_path', 'is_active', 'updated_at']);

            return Company::pluck('id', 'legacy_id')->all();
        }

        // Dry run: pretend ids equal legacy ids so the medicine pass can still count rows.
        return collect($rows)->mapWithKeys(fn ($r) => [$r['legacy_id'] => $r['legacy_id']])->all();
    }

    /**
     * Logo files are `company_<n>_<company-name-slug>.webp`; the name slug identifies the company.
     *
     * @return array<string,string> company-name slug => path relative to public/
     */
    private function matchLogos(string $folder): array
    {
        $dir = public_path($folder);
        if (! is_dir($dir)) {
            $this->warn("Logo folder not found: {$dir}");

            return [];
        }

        $map = [];
        foreach (scandir($dir) as $f) {
            if (preg_match('/^company_\d+_(.*)\.webp$/i', $f, $m)) {
                $map[$this->slug($m[1])] = $folder.'/'.$f;
            }
        }

        return $map;
    }

    /** @return array<int,string> legacy medicine id => path relative to public/ */
    private function matchImages($legacy, string $folder): array
    {
        $dir = public_path($folder);
        if (! is_dir($dir)) {
            $this->warn("Image folder not found: {$dir}");

            return [];
        }

        // name-slug => [n => filename]
        $files = [];
        foreach (scandir($dir) as $f) {
            if (preg_match('/^med_(\d+)_(.*)\.webp$/i', $f, $m)) {
                $files[$this->slug($m[2])][(int) $m[1]] = $f;
            }
        }
        foreach ($files as &$byN) {
            ksort($byN);
        }
        unset($byN);

        // name-slug => [[id, hasPhoto], …] in id order
        $groups = [];
        foreach ($legacy->table('medicines')->whereNull('deleted_at')->orderBy('id')->select('id', 'name', 'photo')->cursor() as $m) {
            $groups[$this->slug($m->name)][] = [$m->id, $m->photo !== null];
        }

        $map = [];
        $stats = ['by_photo' => 0, 'by_all' => 0, 'ambiguous' => 0, 'no_medicine' => 0];
        foreach ($files as $slug => $byN) {
            $list = array_values($byN);
            $rows = $groups[$slug] ?? [];
            if (! $rows) {
                $stats['no_medicine'] += count($list);

                continue;
            }
            $photoRows = array_values(array_filter($rows, fn ($r) => $r[1]));

            if (count($list) === count($photoRows)) {
                $targets = $photoRows;
                $stats['by_photo'] += count($list);
            } elseif (count($list) === count($rows)) {
                $targets = $rows;
                $stats['by_all'] += count($list);
            } else {
                $stats['ambiguous'] += count($list);

                continue;
            }
            foreach ($targets as $i => [$id]) {
                $map[$id] = $folder.'/'.$list[$i];
            }
        }

        $this->line(sprintf(
            '  matched by photo-order: %d · by full-order: %d · skipped (ambiguous): %d · files with no medicine: %d',
            $stats['by_photo'], $stats['by_all'], $stats['ambiguous'], $stats['no_medicine'],
        ));

        return $map;
    }

    private function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($s)), '_');
    }

    private function clean(?string $v): ?string
    {
        $v = $v === null ? null : trim($v);

        return $v === '' ? null : $v;
    }

    private function money(mixed $v): ?string
    {
        return is_numeric($v) && (float) $v > 0 ? number_format((float) $v, 2, '.', '') : null;
    }
}
