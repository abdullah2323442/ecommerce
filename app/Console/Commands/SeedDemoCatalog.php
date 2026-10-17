<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fills a development shop with sample market products across every kind of business, each with a picture, so the home page and the market can be
 * seen with real content. The pictures are drawn here (soft studio-style plates, no brand names, no stolen photos); the owner replaces them with real ones.
 * Safe to run again: a product already there (same seller and title) is left alone.
 */
class SeedDemoCatalog extends Command
{
    protected $signature = 'demo:catalog {--force : Allow it on a production server}';

    protected $description = 'Add sample products with pictures in every category (development only)';

    /** slug => [name, name_bn, unit, hue, [[title, price, shape]...]] */
    private const SECTORS = [
        'wood' => ['Wood', 'কাঠ', 'piece', 32, [['Teak dining chair', 6800, 'chair'], ['Handmade walnut tray', 1450, 'tray'], ['Sheesham bookshelf', 14500, 'shelf'], ['Carved bedside table', 5200, 'table']]],
        'clothing' => ['Clothing', 'পোশাক', 'piece', 200, [['Linen kurta, sand', 2400, 'shirt'], ['Cotton panjabi, ivory', 2900, 'shirt'], ['Jamdani scarf', 3600, 'scarf'], ['Handloom tote', 1250, 'bag']]],
        'electronics' => ['Electronics', 'ইলেকট্রনিক্স', 'piece', 215, [['Wireless earbuds', 4200, 'pods'], ['Slim power bank 20,000 mAh', 2650, 'block'], ['Smart watch, graphite', 7900, 'watch'], ['Desk speaker', 3800, 'speaker']]],
        'home' => ['Home and living', 'ঘর ও জীবন', 'piece', 18, [['Stoneware vase', 1800, 'vase'], ['Brass table lamp', 4600, 'lamp'], ['Cotton throw blanket', 2200, 'block'], ['Ceramic dinner set', 5400, 'bowl']]],
        'beauty' => ['Beauty and care', 'রূপচর্চা', 'piece', 345, [['Rose face serum', 1650, 'bottle'], ['Shea body butter', 950, 'jar'], ['Sandalwood soap, set of 3', 720, 'block'], ['Argan hair oil', 1180, 'bottle']]],
        'books' => ['Books and stationery', 'বই ও স্টেশনারি', 'piece', 52, [['Leather-bound notebook', 880, 'book'], ['Fountain pen, matte black', 2100, 'pen'], ['Bangla poetry collection', 450, 'book'], ['Linen journal, dotted', 640, 'book']]],
        'grocery' => ['Pantry', 'মুদি', 'piece', 95, [['Sundarbans honey, 500 g', 780, 'jar'], ['Cold-pressed mustard oil, 1 L', 540, 'bottle'], ['Premium tea leaves, 250 g', 620, 'jar'], ['Mixed nuts, 400 g', 890, 'jar']]],
        'jewellery' => ['Jewellery', 'গয়না', 'piece', 40, [['Gold-plated hoop earrings', 3200, 'ring'], ['Pearl strand necklace', 5800, 'ring'], ['Silver cuff bracelet', 4400, 'ring'], ['Enamel pendant', 2750, 'ring']]],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to add sample products on a production server. Use --force if you really mean it.');

            return self::FAILURE;
        }

        $seller = User::where('email', 'wholesale@siamsaqua.test')->first() ?? User::where('account_type', 'wholesaler')->first();

        if (! $seller) {
            $this->error('No wholesale seller account found to own the products.');

            return self::FAILURE;
        }

        $seller->forceFill(['categories' => array_values(array_unique(array_merge((array) $seller->categories, array_keys(self::SECTORS)))), 'seller_trusted_at' => $seller->seller_trusted_at ?? now()])->save();

        $made = 0;
        $sort = 10;

        foreach (self::SECTORS as $slug => [$name, $bn, $unit, $hue, $items]) {
            $sector = Sector::firstOrCreate(['slug' => $slug], ['name' => $name, 'name_bn' => $bn, 'unit' => $unit, 'allow_fraction' => false, 'is_active' => true, 'sort' => $sort += 10]);

            foreach ($items as $i => [$title, $price, $shape]) {
                $path = 'listings/demo-'.$slug.'-'.($i + 1).'.jpg';
                Storage::put($path, $this->plate($hue + $i * 7, $shape, $i));

                if (Listing::where('seller_id', $seller->id)->where('title', $title)->exists()) {
                    continue;
                }

                Listing::create([
                    'seller_id' => $seller->id, 'sector_id' => $sector->id, 'title' => $title, 'description' => 'A sample product for the demo shop. Replace it with your own.',
                    'public_price' => $price, 'delivery_charge' => 80, 'stock' => 25, 'status' => 'published', 'photo_path' => $path,
                    'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()->subMinutes($made), 'cod_allowed' => true,
                ]);
                $made++;
            }
        }

        $this->info("Added {$made} sample products.");

        return self::SUCCESS;
    }

    /** A 900 x 900 studio plate: a soft wall and floor in one hue, a shadow, and a simple form that hints at the kind of product. @return string JPEG bytes */
    private function plate(int $hue, string $shape, int $variant): string
    {
        $n = 900;
        $img = imagecreatetruecolor($n, $n);
        imagealphablending($img, true);
        $c = fn (float $h, float $s, float $l) => $this->hsl($img, $h, $s, $l);

        // Wall (a vertical wash), then the floor.
        for ($y = 0; $y < $n; $y++) {
            imageline($img, 0, $y, $n, $y, $c($hue, 0.18, 0.93 - 0.06 * ($y / $n)));
        }
        $horizon = (int) ($n * 0.66);
        for ($y = $horizon; $y < $n; $y++) {
            imageline($img, 0, $y, $n, $y, $c($hue, 0.16, 0.86 - 0.1 * (($y - $horizon) / ($n - $horizon))));
        }

        $cx = 450;
        $base = $horizon + 70;
        $this->shadow($img, $cx, $base, 300, 46);
        $body = $c($hue, 0.42, 0.34);
        $light = $c($hue, 0.34, 0.5);
        $pale = $c($hue, 0.3, 0.78);
        $ink = $c($hue, 0.3, 0.2);

        match ($shape) {
            'chair' => $this->chair($img, $cx, $base, $body, $light),
            'table', 'tray', 'shelf' => $this->furniture($img, $cx, $base, $body, $light, $shape),
            'shirt', 'scarf' => $this->garment($img, $cx, $base, $body, $light, $shape),
            'bag' => $this->bag($img, $cx, $base, $body, $light),
            'pods', 'watch', 'speaker', 'block' => $this->device($img, $cx, $base, $body, $light, $pale, $shape),
            'vase', 'lamp', 'bottle', 'jar', 'bowl' => $this->vessel($img, $cx, $base, $body, $light, $pale, $shape),
            'book', 'pen' => $this->book($img, $cx, $base, $body, $light, $ink, $shape, $variant),
            default => $this->ring($img, $cx, $base, $body, $light, $pale),
        };

        ob_start();
        imagejpeg($img, null, 88);

        return (string) ob_get_clean();
    }

    private function hsl(\GdImage $img, float $h, float $s, float $l): int
    {
        $h = fmod($h, 360) / 360;
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q) {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

            return $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < 1 / 2 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));
        };

        return imagecolorallocate($img, (int) round($f($h + 1 / 3) * 255), (int) round($f($h) * 255), (int) round($f($h - 1 / 3) * 255));
    }

    private function shadow(\GdImage $img, int $cx, int $y, int $w, int $h): void
    {
        // Many faint, nested ellipses blend into a soft contact shadow.
        for ($i = 14; $i >= 1; $i--) {
            imagefilledellipse($img, $cx, $y, (int) ($w * (0.5 + $i / 20)), (int) ($h * (0.5 + $i / 20)), imagecolorallocatealpha($img, 40, 30, 20, 122));
        }
    }

    private function rrect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$px, $py]) {
            imagefilledellipse($img, $px, $py, $r * 2, $r * 2, $color);
        }
    }

    private function chair(\GdImage $img, int $cx, int $base, int $body, int $light): void
    {
        $this->rrect($img, $cx - 140, $base - 430, $cx + 140, $base - 200, 22, $body);     // back
        $this->rrect($img, $cx - 120, $base - 400, $cx + 120, $base - 230, 14, $light);
        $this->rrect($img, $cx - 170, $base - 210, $cx + 170, $base - 150, 18, $body);     // seat
        foreach ([-140, 120] as $dx) {
            $this->rrect($img, $cx + $dx, $base - 150, $cx + $dx + 24, $base, 8, $body);
        }
    }

    private function furniture(\GdImage $img, int $cx, int $base, int $body, int $light, string $shape): void
    {
        if ($shape === 'tray') {
            $this->rrect($img, $cx - 260, $base - 120, $cx + 260, $base - 40, 30, $body);
            $this->rrect($img, $cx - 230, $base - 108, $cx + 230, $base - 62, 22, $light);

            return;
        }
        if ($shape === 'shelf') {
            $this->rrect($img, $cx - 200, $base - 560, $cx + 200, $base, 12, $body);
            foreach ([-380, -210, -40] as $dy) {
                $this->rrect($img, $cx - 180, $base + $dy - 150, $cx + 180, $base + $dy - 18, 6, $light);
            }

            return;
        }
        $this->rrect($img, $cx - 210, $base - 300, $cx + 210, $base - 250, 14, $body);
        foreach ([-190, 160] as $dx) {
            $this->rrect($img, $cx + $dx, $base - 250, $cx + $dx + 30, $base, 8, $body);
        }
        $this->rrect($img, $cx - 170, $base - 245, $cx + 170, $base - 170, 10, $light);
    }

    private function garment(\GdImage $img, int $cx, int $base, int $body, int $light, string $shape): void
    {
        if ($shape === 'scarf') {
            $this->rrect($img, $cx - 120, $base - 520, $cx + 120, $base - 60, 40, $body);
            for ($y = $base - 480; $y < $base - 90; $y += 46) {
                imagefilledrectangle($img, $cx - 120, $y, $cx + 120, $y + 14, $light);
            }

            return;
        }
        imagefilledpolygon($img, [$cx - 90, $base - 520, $cx - 250, $base - 400, $cx - 200, $base - 330, $cx - 140, $base - 360, $cx - 150, $base - 50, $cx + 150, $base - 50, $cx + 140, $base - 360, $cx + 200, $base - 330, $cx + 250, $base - 400, $cx + 90, $base - 520, $cx, $base - 470], $body);
        imagefilledpolygon($img, [$cx - 90, $base - 520, $cx, $base - 470, $cx + 90, $base - 520, $cx + 40, $base - 540, $cx - 40, $base - 540], $light);
    }

    private function bag(\GdImage $img, int $cx, int $base, int $body, int $light): void
    {
        $this->rrect($img, $cx - 190, $base - 330, $cx + 190, $base - 30, 22, $body);
        imagesetthickness($img, 14);
        imagearc($img, $cx, $base - 330, 220, 260, 180, 360, $light);
        imagesetthickness($img, 1);
    }

    private function device(\GdImage $img, int $cx, int $base, int $body, int $light, int $pale, string $shape): void
    {
        if ($shape === 'pods') {
            foreach ([-110, 110] as $dx) {
                imagefilledellipse($img, $cx + $dx, $base - 130, 170, 200, $body);
                imagefilledellipse($img, $cx + $dx, $base - 150, 120, 140, $light);
            }

            return;
        }
        if ($shape === 'watch') {
            $this->rrect($img, $cx - 70, $base - 520, $cx + 70, $base - 20, 20, $light);
            $this->rrect($img, $cx - 150, $base - 380, $cx + 150, $base - 160, 60, $body);
            imagefilledellipse($img, $cx, $base - 270, 190, 190, $pale);

            return;
        }
        if ($shape === 'speaker') {
            $this->rrect($img, $cx - 140, $base - 400, $cx + 140, $base, 40, $body);
            imagefilledellipse($img, $cx, $base - 250, 180, 180, $light);
            imagefilledellipse($img, $cx, $base - 250, 70, 70, $pale);

            return;
        }
        $this->rrect($img, $cx - 220, $base - 260, $cx + 220, $base - 20, 26, $body);
        $this->rrect($img, $cx - 190, $base - 230, $cx + 190, $base - 50, 14, $light);
    }

    private function vessel(\GdImage $img, int $cx, int $base, int $body, int $light, int $pale, string $shape): void
    {
        match ($shape) {
            'vase' => (function () use ($img, $cx, $base, $body, $light) {
                imagefilledellipse($img, $cx, $base - 190, 340, 380, $body);
                $this->rrect($img, $cx - 55, $base - 480, $cx + 55, $base - 330, 20, $body);
                imagefilledellipse($img, $cx - 70, $base - 220, 90, 160, $light);
            })(),
            'lamp' => (function () use ($img, $cx, $base, $body, $light, $pale) {
                $this->rrect($img, $cx - 20, $base - 330, $cx + 20, $base, 8, $body);
                $this->rrect($img, $cx - 110, $base - 30, $cx + 110, $base, 12, $body);
                imagefilledpolygon($img, [$cx - 150, $base - 330, $cx + 150, $base - 330, $cx + 90, $base - 520, $cx - 90, $base - 520], $pale);
            })(),
            'bowl' => (function () use ($img, $cx, $base, $body, $light) {
                imagefilledarc($img, $cx, $base - 160, 440, 380, 0, 180, $body, IMG_ARC_PIE);
                imagefilledellipse($img, $cx, $base - 160, 440, 70, $light);
            })(),
            'jar' => (function () use ($img, $cx, $base, $body, $light, $pale) {
                $this->rrect($img, $cx - 130, $base - 360, $cx + 130, $base, 30, $body);
                $this->rrect($img, $cx - 140, $base - 420, $cx + 140, $base - 360, 14, $light);
                $this->rrect($img, $cx - 90, $base - 280, $cx + 90, $base - 120, 10, $pale);
            })(),
            default => (function () use ($img, $cx, $base, $body, $light, $pale) {
                $this->rrect($img, $cx - 100, $base - 400, $cx + 100, $base, 28, $body);
                $this->rrect($img, $cx - 36, $base - 500, $cx + 36, $base - 400, 8, $light);
                $this->rrect($img, $cx - 70, $base - 280, $cx + 70, $base - 130, 8, $pale);
            })(),
        };
    }

    private function book(\GdImage $img, int $cx, int $base, int $body, int $light, int $ink, string $shape, int $variant): void
    {
        if ($shape === 'pen') {
            imagesetthickness($img, 34);
            imageline($img, $cx - 220, $base - 60, $cx + 220, $base - 320, $body);
            imagesetthickness($img, 14);
            imageline($img, $cx + 180, $base - 296, $cx + 240, $base - 332, $light);
            imagesetthickness($img, 1);

            return;
        }
        $w = 150 + ($variant % 2) * 20;
        $this->rrect($img, $cx - $w, $base - 440, $cx + $w, $base, 12, $body);
        $this->rrect($img, $cx - $w + 26, $base - 440, $cx - $w + 40, $base, 2, $light);
        imagefilledrectangle($img, $cx - $w + 70, $base - 340, $cx + $w - 40, $base - 326, $light);
        imagefilledrectangle($img, $cx - $w + 70, $base - 300, $cx + $w - 90, $base - 290, $light);
    }

    private function ring(\GdImage $img, int $cx, int $base, int $body, int $light, int $pale): void
    {
        imagesetthickness($img, 26);
        imagearc($img, $cx, $base - 220, 380, 380, 0, 360, $body);
        imagesetthickness($img, 10);
        imagearc($img, $cx, $base - 220, 380, 380, 200, 320, $pale);
        imagesetthickness($img, 1);
        imagefilledellipse($img, $cx, $base - 410, 56, 56, $light);
    }
}
