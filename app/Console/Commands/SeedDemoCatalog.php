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

    /** What each product says about itself. */
    private const DESCRIPTIONS = [
        'Teak dining chair' => 'Solid teak frame with a hand-woven cane back and a gently contoured seat. Finished with a natural oil that keeps the grain visible. Sold singly; they pair well in sets of four or six.',
        'Handmade walnut tray' => 'Cut and joined by hand from a single walnut board, with cut-out handles and a food-safe oil finish. Good for serving tea, or as a catch-all on a dresser.',
        'Sheesham bookshelf' => 'A tall five-shelf bookcase in sheesham with a rich, varied grain. Solid joinery, adjustable feet for uneven floors. Flat-packed for delivery with simple assembly.',
        'Carved bedside table' => 'A single-drawer bedside table in teak with a hand-carved floral drawer front, an open lower shelf and a brass knob. Finished in natural oil.',
        'Linen kurta, sand' => 'A relaxed kurta in washed linen with a mandarin collar and a two-button placket. Breathable and soft, it gets better with every wash. True to size.',
        'Cotton panjabi, ivory' => 'A classic panjabi in fine combed cotton with a full button placket and a clean stand collar. Light enough for the humid months, smart enough for Friday prayers and festive days.',
        'Jamdani scarf' => 'A lightweight scarf woven in the Jamdani tradition, with a delicate gold and grey motif and a hand-knotted fringe. Soft drape, ideal for dressing up a simple outfit.',
        'Handloom tote' => 'A roomy tote in handloom cotton with vegetable-tanned leather handles and brass rivets. Holds a laptop, a book and a bottle with room to spare.',
        'Wireless earbuds' => 'Compact true-wireless earbuds with a pocket-sized charging case. Comfortable silicone tips, clear calls and about six hours of playback per charge, with the case adding three more charges.',
        'Slim power bank 20,000 mAh' => 'A slim aluminium power bank with 20,000 mAh of capacity, one USB-C and one USB-A port, and a small LED level indicator. Charges a typical phone about four times.',
        'Smart watch, graphite' => 'A round smart watch with a graphite aluminium case, soft silicone strap and a bright always-on display. Tracks steps, sleep and heart rate, and shows phone notifications.',
        'Desk speaker' => 'A compact Bluetooth speaker wrapped in charcoal fabric, with surprisingly full sound for its size, touch controls on top and about ten hours of playback.',
        'Stoneware vase' => 'A hand-thrown stoneware vase with a matte cream glaze and visible throwing lines. Each one is slightly different. Water-tight, and lovely with dried grasses or a few stems.',
        'Brass table lamp' => 'A brushed brass table lamp with a natural linen drum shade and a cloth-covered cord. Warm light for a bedside or a reading corner. Takes a standard E27 bulb.',
        'Cotton throw blanket' => 'A soft, textured cotton throw in oatmeal with a hand-knotted fringe. Generous enough for a sofa, light enough to use year-round.',
        'Ceramic dinner set' => 'A stackable dinner set in matte off-white stoneware: plates and bowls with a soft speckle. Dishwasher and microwave safe.',
        'Rose face serum' => 'A lightweight face serum with rose extract and hyaluronic acid, in an amber glass dropper bottle that protects the formula. Suitable for most skin types. 30 ml.',
        'Shea body butter' => 'A rich whipped body butter with unrefined shea and a light natural scent, in a frosted glass jar with a wooden lid. Melts in on contact. 200 g.',
        'Sandalwood soap, set of 3' => 'Three cold-process soap bars scented with sandalwood, tied with natural twine. Gentle on skin, long-lasting and plastic-free. About 100 g each.',
        'Argan hair oil' => 'A light argan hair oil in a pump bottle that tames frizz and adds shine without weighing hair down. Use a pump on damp or dry hair. 100 ml.',
        'Leather-bound notebook' => 'A refillable A5 notebook in full-grain tan leather with an elastic closure, a ribbon marker and 192 pages of 100 gsm cream paper.',
        'Fountain pen, matte black' => 'A weighty fountain pen in matte black aluminium with a steel nib and a clip that sits flat. Takes standard international cartridges. Smooth from the first line.',
        'Bangla poetry collection' => 'A hardback collection of modern Bangla poetry, sewn binding in a plain green cloth cover. A thoughtful gift for a reader.',
        'Linen journal, dotted' => 'A dotted-grid journal in a natural linen cover with a ribbon bookmark and 160 pages of thick paper that takes fountain pen ink without bleed-through.',
        'Sundarbans honey, 500 g' => 'Raw forest honey collected from the Sundarbans in season. Unheated and unfiltered, with a floral, slightly smoky taste. 500 g.',
        'Cold-pressed mustard oil, 1 L' => 'Traditional kachi ghani mustard oil, cold-pressed from locally grown seeds. Sharp, aromatic and ideal for bhorta, pickles and frying. 1 litre.',
        'Premium tea leaves, 250 g' => 'Whole-leaf black tea from the hills of Sylhet, picked in the first flush for a bright, malty cup. 250 g in a resealable tin.',
        'Mixed nuts, 400 g' => 'A generous mix of roasted almonds, cashews, pistachios and walnuts, lightly salted. Packed fresh in a resealable pouch. 400 g.',
        'Gold-plated hoop earrings' => 'Classic medium hoops in 18k gold plating over brass, hypoallergenic posts and a secure clasp. Light enough to wear all day.',
        'Pearl strand necklace' => 'A single strand of freshwater cultured pearls hand-knotted on silk thread, with a gold-plated clasp. 45 cm.',
        'Silver cuff bracelet' => 'An open cuff in polished 925 sterling silver with a slim, modern profile. Adjusts gently to fit most wrists.',
        'Enamel pendant' => 'A small round pendant in deep teal enamel on a fine gold-plated chain, adjustable from 40 to 45 cm. Comes in a gift pouch.',
    ];

    /** Real studio photographs (made in Flow, saved in storage/app/private/demo-src) by product title. A product without one keeps its drawn plate. */
    private const PHOTOS = [
        'Teak dining chair' => 'teak-dining-chair', 'Handmade walnut tray' => 'walnut-tray', 'Sheesham bookshelf' => 'sheesham-bookshelf', 'Carved bedside table' => 'carved-bedside-table',
        'Linen kurta, sand' => 'linen-kurta', 'Cotton panjabi, ivory' => 'cotton-panjabi', 'Jamdani scarf' => 'jamdani-scarf', 'Handloom tote' => 'handloom-tote',
        'Wireless earbuds' => 'wireless-earbuds', 'Slim power bank 20,000 mAh' => 'power-bank', 'Smart watch, graphite' => 'smart-watch', 'Desk speaker' => 'desk-speaker',
        'Stoneware vase' => 'stoneware-vase', 'Brass table lamp' => 'brass-table-lamp', 'Cotton throw blanket' => 'throw-blanket', 'Ceramic dinner set' => 'dinner-set',
        'Rose face serum' => 'face-serum', 'Shea body butter' => 'body-butter', 'Sandalwood soap, set of 3' => 'sandalwood-soap', 'Argan hair oil' => 'hair-oil',
        'Leather-bound notebook' => 'leather-notebook', 'Fountain pen, matte black' => 'fountain-pen', 'Bangla poetry collection' => 'poetry-book', 'Linen journal, dotted' => 'linen-journal',
        'Sundarbans honey, 500 g' => 'honey', 'Cold-pressed mustard oil, 1 L' => 'mustard-oil', 'Premium tea leaves, 250 g' => 'tea', 'Mixed nuts, 400 g' => 'mixed-nuts',
        'Gold-plated hoop earrings' => 'hoop-earrings', 'Pearl strand necklace' => 'pearl-necklace', 'Silver cuff bracelet' => 'silver-cuff', 'Enamel pendant' => 'enamel-pendant',
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
                Storage::put($path, $this->photo($title) ?? $this->plate($hue + $i * 7, $shape, $i));

                $say = self::DESCRIPTIONS[$title] ?? 'A sample product for the demo shop.';

                if ($old = Listing::where('seller_id', $seller->id)->where('title', $title)->first()) {
                    $old->update(['description' => $say, 'photo_path' => $path]);

                    continue;
                }

                Listing::create([
                    'seller_id' => $seller->id, 'sector_id' => $sector->id, 'title' => $title, 'description' => $say,
                    'public_price' => $price, 'delivery_charge' => 80, 'stock' => 25, 'status' => 'published', 'photo_path' => $path,
                    'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()->subMinutes($made), 'cod_allowed' => true,
                ]);
                $made++;
            }
        }

        $this->info("Added {$made} sample products.");

        return self::SUCCESS;
    }

    /** The real photograph for a product, as a JPEG, or null when there is none yet. */
    private function photo(string $title): ?string
    {
        $file = storage_path('app/private/demo-src/'.(self::PHOTOS[$title] ?? '-').'.png');

        if (! is_file($file) || ! ($img = @imagecreatefrompng($file))) {
            return null;
        }

        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
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
            'lamp' => (function () use ($img, $cx, $base, $body, $pale) {
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
