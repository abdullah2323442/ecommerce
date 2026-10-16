<?php

namespace App\Services\Site;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Media\ImageCompressor;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The words and look of the site, edited by the owner (no code, no .env). Saved groups sit over the defaults in config/site.php, and an empty
 * value falls back to the older config/brand.php, so the site is complete before anyone saves anything. Reads are cached and the cache is cleared on every
 * save. Links the owner types are checked: only a path on this site or an https address is accepted, never a script.
 */
class Site
{
    public const GROUPS = ['brand', 'contact', 'social', 'footer', 'home', 'announcement', 'seo'];

    private const CACHE = 'site.settings';

    public function __construct(private ActivityLogger $activity, private ImageCompressor $images) {}

    /** Every group, saved values over the defaults. @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE, function () {
            $saved = SiteSetting::all()->pluck('value', 'group')->all();
            $out = [];

            foreach (self::GROUPS as $group) {
                $out[$group] = $this->merge((array) config("site.{$group}"), (array) ($saved[$group] ?? []), $group);
            }

            return $out;
        });
    }

    public function group(string $group): array
    {
        return $this->all()[$group] ?? [];
    }

    /**
     * Save one group. Only known keys are kept; links are checked; a list replaces the old one.
     *
     * @throws StockException
     */
    public function save(string $group, array $data, User $by): array
    {
        if (! in_array($group, self::GROUPS, true)) {
            throw new StockException('Unknown part of the site.');
        }

        $clean = $this->clean($group, $data);
        $before = $this->group($group);
        SiteSetting::updateOrCreate(['group' => $group], ['value' => $clean, 'updated_by' => $by->id]);
        Cache::forget(self::CACHE);

        // The log says which fields changed, never what the texts were.
        $changed = array_keys(array_filter(Arr::dot($clean), fn ($v, $k) => Arr::get(Arr::dot($before), $k) !== $v, ARRAY_FILTER_USE_BOTH));
        $this->activity->record('site.saved', null, ['group' => $group, 'fields' => array_slice($changed, 0, 20)], $by);

        return $this->group($group);
    }

    // ------------------------------------------------------------------ the logo and the small icon

    /** @throws StockException */
    public function putImage(string $which, UploadedFile $file, User $by): void
    {
        if (! in_array($which, ['logo', 'favicon'], true)) {
            throw new StockException('Unknown picture.');
        }

        $brand = $this->group('brand');
        $old = $brand[$which] ?? null;
        $brand[$which] = $this->images->store($file, 'brand');
        SiteSetting::updateOrCreate(['group' => 'brand'], ['value' => $this->stored('brand', $brand), 'updated_by' => $by->id]);
        Cache::forget(self::CACHE);

        if ($old && Storage::exists($old)) {
            Storage::delete($old);
        }

        $this->activity->record('site.image', null, ['which' => $which], $by);
    }

    public function removeImage(string $which, User $by): void
    {
        $brand = $this->group('brand');

        if (! empty($brand[$which])) {
            Storage::delete($brand[$which]);
            $brand[$which] = null;
            SiteSetting::updateOrCreate(['group' => 'brand'], ['value' => $this->stored('brand', $brand), 'updated_by' => $by->id]);
            Cache::forget(self::CACHE);
            $this->activity->record('site.image_removed', null, ['which' => $which], $by);
        }
    }

    // ------------------------------------------------------------------ what the pages are given

    /**
     * Everything the public pages need, already in the viewer's language. Empty values are null, so a page draws a line only when it has one.
     *
     * @return array<string, mixed>
     */
    public function forViewer(?string $locale = null): array
    {
        $bn = ($locale ?? app()->getLocale()) === 'bn';
        $all = $this->all();
        $text = fn (array $pair) => ($bn ? ($pair['bn'] ?? null) : null) ?: ($pair['en'] ?? null) ?: null;
        $brand = $all['brand'];
        $contact = $all['contact'];
        $version = substr(md5(json_encode([$brand['logo'], $brand['favicon']])), 0, 8);

        return [
            'brand' => [
                'name' => ($bn ? ($brand['name_bn'] ?: config('brand.name_bn')) : null) ?: ($brand['name'] ?: (string) config('brand.name')),
                'tagline' => $text($brand['tagline']),
                'theme_color' => $brand['theme_color'] ?? '#0f766e',
                'logo' => $brand['logo'] ? route('brand.image', ['which' => 'logo', 'v' => $version]) : (config('brand.logo') ?: null),
                'favicon' => $brand['favicon'] ? route('brand.image', ['which' => 'favicon', 'v' => $version]) : null,
                'phone' => $contact['phone'] ?: config('brand.phone'), 'email' => $contact['email'] ?: config('brand.email'), 'address' => $contact['address'] ?: config('brand.address'),
                'whatsapp' => $contact['whatsapp'] ?: config('brand.whatsapp'), 'messenger' => $contact['messenger'] ?: config('brand.messenger'),
                'drug_license' => $contact['drug_license'] ?: config('brand.drug_license'), 'hours' => $text($contact['hours']),
            ],
            'site' => [
                'social' => array_filter($all['social']),
                'footer' => [
                    'about' => $text($all['footer']['about']), 'copyright' => $text($all['footer']['copyright']),
                    'links' => collect($all['footer']['links'])->filter(fn ($l) => ! empty($l['enabled']) && ! empty($l['url']))
                        ->map(fn ($l) => ['label' => $bn && ! empty($l['label_bn']) ? $l['label_bn'] : $l['label'], 'translate' => ! ($bn && ! empty($l['label_bn'])), 'url' => $l['url']])->values()->all(),
                ],
                'home' => [
                    'hero_title' => $text($all['home']['hero_title']), 'hero_text' => $text($all['home']['hero_text']),
                    'sections' => collect($all['home']['sections'])->filter(fn ($s) => ! empty($s['enabled']))->map(fn ($s) => ['key' => $s['key'], 'open' => ($s['status'] ?? 'open') === 'open', 'href' => config("site.section_links.{$s['key']}")])->values()->all(),
                ],
                'announcement' => ! empty($all['announcement']['enabled']) && ($t = $text($all['announcement']['text'])) ? ['text' => $t, 'link' => $all['announcement']['link']] : null,
                'seo' => ['description' => $text($all['seo']['description'])],
            ],
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** Saved values over the defaults, one level of pairs deep; a list (footer links, home cards) is taken whole. */
    private function merge(array $defaults, array $saved, string $group): array
    {
        $out = $defaults;

        foreach ($saved as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }

            $out[$key] = is_array($defaults[$key]) && Arr::isAssoc($defaults[$key]) ? array_merge($defaults[$key], (array) $value) : $value;
        }

        // A card the owner never saw (added later in code) still appears.
        if ($group === 'home') {
            $have = collect($out['sections'])->pluck('key')->all();
            foreach ($defaults['sections'] as $s) {
                if (! in_array($s['key'], $have, true)) {
                    $out['sections'][] = $s;
                }
            }
        }

        return $out;
    }

    /** What is stored for a group (the same shape as the defaults). */
    private function stored(string $group, array $data): array
    {
        return Arr::only($data, array_keys((array) config("site.{$group}")));
    }

    /** @throws StockException */
    private function clean(string $group, array $d): array
    {
        $str = fn ($v, int $max = 200) => ($v = trim((string) ($v ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $pair = fn ($v, int $max = 300) => ['en' => $str($v['en'] ?? null, $max), 'bn' => $str($v['bn'] ?? null, $max)];
        $url = function ($v): ?string {
            $v = trim((string) ($v ?? ''));
            if ($v === '') {
                return null;
            }
            // A path on this site, or an https address. Never javascript:, data: or http://.
            if (! preg_match('~^(/[A-Za-z0-9_\-./?=&%#]*|https://[^\s<>"\']+)$~', $v) || str_starts_with($v, '//')) {
                throw new StockException('A link must start with / or https://');
            }

            return mb_substr($v, 0, 300);
        };

        return match ($group) {
            'brand' => [
                'name' => $str($d['name'] ?? null, 80), 'name_bn' => $str($d['name_bn'] ?? null, 80), 'tagline' => $pair($d['tagline'] ?? [], 120),
                'logo' => $this->group('brand')['logo'] ?? null, 'favicon' => $this->group('brand')['favicon'] ?? null,   // pictures change only through their own upload
                'theme_color' => ($c = $str($d['theme_color'] ?? null, 20)) !== null && ! preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? throw new StockException('Choose a colour like #0f766e.') : $c,
            ],
            'contact' => [
                'phone' => $str($d['phone'] ?? null, 30), 'email' => ($e = $str($d['email'] ?? null, 120)) && ! filter_var($e, FILTER_VALIDATE_EMAIL) ? throw new StockException('Write a real email address.') : $e,
                'address' => $str($d['address'] ?? null, 250), 'whatsapp' => ($w = preg_replace('/\D+/', '', (string) ($d['whatsapp'] ?? ''))) !== '' ? mb_substr($w, 0, 15) : null,
                'messenger' => $str($d['messenger'] ?? null, 80), 'drug_license' => $str($d['drug_license'] ?? null, 60), 'hours' => $pair($d['hours'] ?? [], 160),
            ],
            'social' => collect(['facebook', 'instagram', 'youtube', 'tiktok'])->mapWithKeys(fn ($k) => [$k => $url($d[$k] ?? null)])->all(),
            'footer' => [
                'about' => $pair($d['about'] ?? [], 400), 'copyright' => $pair($d['copyright'] ?? [], 160),
                'links' => collect((array) ($d['links'] ?? []))->take(30)->map(fn ($l) => [
                    'label' => $str($l['label'] ?? null, 60), 'label_bn' => $str($l['label_bn'] ?? null, 60), 'url' => $url($l['url'] ?? null), 'enabled' => (bool) ($l['enabled'] ?? true),
                ])->filter(fn ($l) => $l['label'] !== null && $l['url'] !== null)->values()->all(),
            ],
            'home' => [
                'hero_title' => $pair($d['hero_title'] ?? [], 80), 'hero_text' => $pair($d['hero_text'] ?? [], 300),
                'sections' => $this->sections((array) ($d['sections'] ?? [])),
            ],
            'announcement' => ['enabled' => (bool) ($d['enabled'] ?? false), 'text' => $pair($d['text'] ?? [], 200), 'link' => $url($d['link'] ?? null)],
            'seo' => ['description' => $pair($d['description'] ?? [], 300)],
        };
    }

    /** The home cards in the order given: only known cards, each once, open or soon, shown or hidden. @throws StockException */
    private function sections(array $rows): array
    {
        $known = array_keys((array) config('site.section_links'));
        $out = [];

        foreach ($rows as $r) {
            $key = (string) ($r['key'] ?? '');

            if (! in_array($key, $known, true) || isset($out[$key])) {
                continue;
            }

            $out[$key] = ['key' => $key, 'enabled' => (bool) ($r['enabled'] ?? true), 'status' => ($r['status'] ?? 'open') === 'soon' ? 'soon' : 'open'];
        }

        // Any card left out of the list is kept (shown) so a mistake never removes a shop from the site.
        foreach ($known as $key) {
            $out[$key] ??= ['key' => $key, 'enabled' => true, 'status' => 'open'];
        }

        return array_values($out);
    }
}
