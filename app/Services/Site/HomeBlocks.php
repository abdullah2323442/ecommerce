<?php

namespace App\Services\Site;

use App\Services\Stock\StockException;

/**
 * The home page as an ordered list of blocks the owner arranges in the page builder (Admin > Site design > Home page builder). Each block has a type
 * from a fixed list and a few settings of fixed kinds, so what the owner saves can never be anything but text, a safe link, a picture of ours, or a
 * choice from a short list. A setting left empty means "use the shop's own wording".
 */
class HomeBlocks
{
    public const MAX = 30;

    /** Kinds: pair (English and Bangla line), area (a longer pair), url, image, int:min:max, enum:a|b|c. */
    public const TYPES = [
        'hero' => ['single' => true, 'fields' => ['title' => 'pair', 'text' => 'area', 'button_label' => 'pair', 'button_link' => 'url']],
        'categories' => ['single' => true, 'fields' => ['title' => 'pair']],
        'arrivals' => ['single' => true, 'fields' => ['title' => 'pair', 'count' => 'int:4:12']],
        'pharmacy' => ['single' => true, 'fields' => ['title' => 'pair', 'text' => 'area']],
        'food' => ['single' => true, 'fields' => ['title' => 'pair']],
        'games' => ['single' => true, 'fields' => ['title' => 'pair']],
        'promises' => ['single' => true, 'fields' => ['p1_title' => 'pair', 'p1_line' => 'pair', 'p2_title' => 'pair', 'p2_line' => 'pair', 'p3_title' => 'pair', 'p3_line' => 'pair']],
        'banner' => ['single' => false, 'fields' => ['heading' => 'pair', 'text' => 'area', 'button_label' => 'pair', 'button_link' => 'url', 'image' => 'image', 'tone' => 'enum:light|dark|gold', 'align' => 'enum:left|center', 'height' => 'enum:sm|md|lg']],
        'text' => ['single' => false, 'fields' => ['heading' => 'pair', 'body' => 'area', 'align' => 'enum:left|center']],
        'spacer' => ['single' => false, 'fields' => ['size' => 'enum:sm|md|lg']],
    ];

    /** The page as it stands before the owner changes anything. @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        return collect(['hero', 'categories', 'arrivals', 'pharmacy', 'food', 'games', 'promises'])->map(fn ($t) => ['id' => $t, 'type' => $t, 'enabled' => true, 'props' => []])->all();
    }

    /**
     * What is stored: only known types and settings, cleaned. A page may hold one of each single block and up to MAX blocks in all.
     *
     * @param  array<int, mixed>  $input
     * @return list<array<string, mixed>>
     *
     * @throws StockException
     */
    public function clean(array $input): array
    {
        if (count($input) > self::MAX) {
            throw new StockException('A page can have up to 30 blocks.');
        }

        $out = [];
        $ids = [];
        $singles = [];

        foreach ($input as $b) {
            $b = (array) $b;
            $type = (string) ($b['type'] ?? '');

            if (! isset(self::TYPES[$type])) {
                continue;
            }

            if (self::TYPES[$type]['single']) {
                if (isset($singles[$type])) {
                    throw new StockException('Each of these blocks can be on the page only once.');
                }
                $singles[$type] = true;
            }

            $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($b['id'] ?? ''))) ?: $type;
            $id = mb_substr($id, 0, 24);
            while (isset($ids[$id])) {
                $id = mb_substr($id, 0, 18).'-'.substr(bin2hex(random_bytes(3)), 0, 5);
            }
            $ids[$id] = true;

            $props = [];
            foreach (self::TYPES[$type]['fields'] as $name => $kind) {
                $v = $this->field($kind, ((array) ($b['props'] ?? []))[$name] ?? null);

                if ($v !== null) {
                    $props[$name] = $v;
                }
            }

            $out[] = ['id' => $id, 'type' => $type, 'enabled' => (bool) ($b['enabled'] ?? true), 'props' => $props];
        }

        return $out;
    }

    /** The picture file names a list of blocks points to. @param list<array<string, mixed>> $blocks @return list<string> */
    public static function images(array $blocks): array
    {
        return collect($blocks)->map(fn ($b) => $b['props']['image'] ?? null)->filter()->values()->all();
    }

    private function field(string $kind, mixed $v): mixed
    {
        $str = fn ($x, int $max) => ($x = trim(strip_tags((string) ($x ?? '')))) === '' ? null : mb_substr($x, 0, $max);

        if ($kind === 'pair' || $kind === 'area') {
            $max = $kind === 'pair' ? 120 : 400;
            $pair = ['en' => $str(((array) $v)['en'] ?? null, $max), 'bn' => $str(((array) $v)['bn'] ?? null, $max)];

            return $pair['en'] === null && $pair['bn'] === null ? null : $pair;
        }

        if ($kind === 'url') {
            $x = trim((string) ($v ?? ''));

            if ($x === '') {
                return null;
            }

            // A path on this site, or an https address. Never javascript:, data: or http://.
            if (! preg_match('~^(/[A-Za-z0-9_\-./?=&%#]*|https://[^\s<>"\']+)$~', $x) || str_starts_with($x, '//')) {
                throw new StockException('A link must start with / or https://');
            }

            return mb_substr($x, 0, 300);
        }

        if ($kind === 'image') {
            // Only a file we stored ourselves: a plain file name, nothing that could point elsewhere.
            $x = trim((string) ($v ?? ''));

            return preg_match('/^[A-Za-z0-9_-]{8,64}\.(webp|jpg|jpeg|png)$/', $x) ? $x : null;
        }

        if (str_starts_with($kind, 'int:')) {
            [, $min, $max] = explode(':', $kind);

            return $v === null || $v === '' ? null : max((int) $min, min((int) $max, (int) $v));
        }

        if (str_starts_with($kind, 'enum:')) {
            return in_array($v, explode('|', substr($kind, 5)), true) ? $v : null;
        }

        return null;
    }
}
