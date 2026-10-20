<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * More sample products for a development shop, on top of demo:catalog: a second, richer range in every category, each with a photograph made in Flow
 * (storage/app/private/demo-src/cells). The prices and descriptions are invented. It refuses to run on production and is safe to run again.
 */
class SeedDemoMore extends Command
{
    protected $signature = 'demo:more {--force : Allow it on a production server}';

    protected $description = 'Add a second range of sample products with photographs (development only)';

    /** sector slug => [[title, price, photo file, description]...] */
    private const RANGE = [
        'electronics' => [
            ['Ultra-thin laptop, 14 inch', 118000, 'e-laptop', 'A 14-inch ultra-thin laptop in brushed aluminium, with a bright 2.8K display, 16 GB of memory and a 512 GB SSD. Up to 14 hours of battery. 2 years warranty.'],
            ['Titanium smartphone, 256 GB', 142000, 'e-phone', 'A flagship smartphone in a titanium frame with a triple camera, 256 GB of storage and a bright all-day display. Official warranty included.'],
            ['Wireless over-ear headphones', 24500, 'e-headphones', 'Over-ear wireless headphones in matte black and gold, with active noise cancelling, 40 hours of playback and soft memory-foam cushions.'],
            ['Mirrorless camera, silver', 98000, 'e-camera', 'A compact mirrorless camera with a fast prime lens, a 26 MP sensor, a classic dial layout and Wi-Fi sharing. Ready for travel and street photography.'],
            ['Leather strap smartwatch', 21500, 'e-watch', 'A smartwatch with a square display and a hand-stitched leather strap. Tracks heart rate, sleep and workouts. Five days of battery.'],
            ['Slim tablet with stand', 46500, 'e-tablet', 'A slim 11-inch tablet with a sharp display and four speakers, sold with an aluminium stand. 128 GB of storage.'],
            ['Fabric bluetooth speaker', 6900, 'e-speaker', 'A cylindrical speaker wrapped in charcoal fabric, with 360-degree sound, touch controls and twelve hours of playback.'],
            ['Wireless game controller', 7200, 'e-controller', 'A wireless controller in matte white with textured grips, responsive triggers and a 30-hour battery. Works with PC and phones.'],
            ['Gaming laptop, 15 inch', 168000, 'e-gaming-laptop', 'A 15-inch gaming laptop with a 165 Hz display, a dedicated graphics card, 16 GB of memory and a backlit RGB keyboard. Strong cooling for long sessions. 2 years warranty.'],
            ['VR headset with controllers', 62000, 'e-vr', 'A standalone virtual reality headset with two motion controllers, sharp lenses and no cables or PC needed. Comes with 128 GB of storage.'],
            ['Portable HD projector', 28500, 'e-projector', 'A compact projector for movie nights: a bright image up to 120 inches, built-in speaker, HDMI and USB, and a small body that fits a bag.'],
            ['Air purifier with HEPA filter', 19500, 'e-purifier', 'A tall air purifier with a true HEPA filter for dust, smoke and pollen, a quiet night mode and a clean-air indicator. Suits rooms up to 40 square metres.'],
            ['27 inch 4K monitor', 54000, 'e-monitor', 'A 27-inch 4K monitor with accurate colour, a slim bezel and a height-adjustable stand. USB-C with 65 W charging.'],
            ['Compact mechanical keyboard', 8900, 'e-keyboard', 'A compact mechanical keyboard with hot-swappable switches, two-tone keycaps and Bluetooth or USB connection.'],
            ['Ergonomic wireless mouse', 6400, 'e-mouse', 'A sculpted wireless mouse with a thumb wheel, silent clicks and a rechargeable battery that lasts weeks. Works on glass.'],
            ['Portable SSD, 1 TB', 11500, 'e-ssd', 'A pocket-sized 1 TB solid-state drive in anodised aluminium with USB-C. Reads at up to 1,000 MB per second.'],
            ['4K action camera', 31000, 'e-action-cam', 'A rugged action camera that films 4K at 60 frames per second, with image stabilisation and waterproofing to 10 metres without a case.'],
            ['Fitness tracker band', 3900, 'e-band', 'A slim fitness band with a bright colour display, heart rate, sleep and step tracking, and a battery that lasts two weeks.'],
            ['Soundbar with fabric grille', 15800, 'e-soundbar', 'A 2.1-channel soundbar with a wireless subwoofer output, Bluetooth, HDMI ARC and a clear-dialogue mode.'],
            ['Matte silicone phone case', 1200, 'e-case', 'A soft-touch silicone case in olive with a microfibre lining and raised edges that protect the screen and the camera.'],
        ],
        'clothing' => [
            ['Indigo denim jacket', 5400, 'c-denim-jacket', 'A classic trucker jacket in rigid indigo denim with copper buttons and two chest pockets. It softens and fades with wear. True to size.'],
            ['Silk saree with gold zari border', 14500, 'c-silk-saree', 'A cream silk saree with a wide hand-woven gold zari border and a soft, fluid drape. Comes with an unstitched blouse piece.'],
            ['Embroidered cotton panjabi', 3800, 'c-panjabi', 'A white cotton panjabi with fine tonal embroidery along the placket and collar. Light, soft and made for festive days.'],
            ['Slim indigo jeans', 3900, 'c-jeans', 'Slim-fit jeans in stretch denim with a deep indigo wash and a mid rise. Comfortable for a full day.'],
            ['White leather sneakers', 6800, 'c-sneakers', 'Minimal low-top sneakers in smooth white leather with a cream sole and a leather lining. A clean pair that goes with everything.'],
            ['Structured leather handbag', 9800, 'c-handbag', 'A structured top-handle bag in grained tan leather with a flap closure, a detachable strap and a suede-lined interior.'],
            ['Striped oxford shirt', 2900, 'c-stripe-shirt', 'A blue and white striped shirt in crisp oxford cotton with a button-down collar and a regular fit.'],
            ['Silk scarf, red and gold', 4200, 'c-red-scarf', 'A hand-woven silk scarf in deep red with gold brocade and a knotted fringe. Wear it as a stole or a wrap.'],
        ],
        'wood' => [
            ['Round walnut dining table with four chairs', 62000, 'w-dining-table', 'A round dining table in solid walnut with four matching chairs and linen seat cushions. Seats four, or six with extra chairs.'],
            ['Carved teak wardrobe', 78000, 'w-wardrobe', 'A two-door wardrobe in seasoned teak with hand-carved floral panels and brass handles. Hanging rail and shelves inside.'],
            ['Teak bed with caned headboard', 54000, 'w-bed', 'A queen-size bed frame in teak with a hand-woven cane headboard and sturdy slats. Mattress not included.'],
            ['Oak rocking chair', 21500, 'w-rocking-chair', 'A hand-crafted rocking chair in light oak with a smooth, contoured back. Finished in natural oil.'],
        ],
        'jewellery' => [
            ['Bridal necklace set, 22 carat gold', 385000, 'j-bridal-necklace', 'A bridal choker necklace with matching earrings in 22 carat gold, finished with fine filigree and hanging drops. Sold with a certificate.'],
            ['Diamond solitaire ring', 215000, 'j-diamond-ring', 'A solitaire ring with a brilliant-cut diamond on a slim white-gold band. Supplied in a velvet box with a grading certificate.'],
            ['Gold and red bangle set', 128000, 'j-bangles', 'A set of traditional bangles in gold with hand-set detail and red lacquer bands. Sold as a stack of eight.'],
            ['Gold jhumka earrings with pearls', 46500, 'j-jhumka', 'Classic jhumka earrings in gold with a filigree dome and freshwater pearl drops. Secure screw backs.'],
        ],
        'beauty' => [
            ['Rose eau de parfum, 50 ml', 5200, 'b-perfume', 'A soft floral eau de parfum with rose, white musk and a warm woody base, in a faceted glass bottle with a gold cap. 50 ml.'],
            ['Matte lipstick, dusty rose', 1450, 'b-lipstick', 'A creamy matte lipstick in a dusty rose shade, in a gold-tone case. Comfortable and long wearing.'],
            ['Rose petal face cream', 1850, 'b-face-cream', 'A rich daily face cream with rose water and shea in a frosted glass jar with a gold lid. 50 g.'],
            ['Vitamin C serum, set of three', 3900, 'b-serum-trio', 'Three 30 ml dropper bottles of a brightening vitamin C serum in amber glass with gold collars.'],
        ],
        'grocery' => [
            ['Forest honey in a glass jar', 980, 'p-honey-jar', 'Raw honey in a cork-stoppered glass jar, with a wooden dipper. Unheated and unfiltered. 500 g.'],
        ],
        'home' => [
            ['Brass tea set with tray', 7800, 'h-brass-tea-set', 'A hand-hammered brass kettle with two cups on a matching tray. Polished by hand and finished with a protective lacquer.'],
            ['Speckled ceramic dinner set, 12 pieces', 8600, 'h-ceramic-set', 'A 12-piece dinner set in speckled ceramic with a soft sage glaze: four plates, four side plates and four bowls. Dishwasher safe.'],
        ],
        'books' => [
            ['Linen hardcover journal with brass bookmark', 1650, 'k-book-pen', 'A hardcover journal bound in dark green linen with 200 pages of thick cream paper and a polished brass bookmark.'],
        ],
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

        $made = 0;
        $n = 100;

        foreach (self::RANGE as $slug => $rows) {
            $sector = Sector::where('slug', $slug)->first();

            if (! $sector) {
                $this->warn("Skipped {$slug}: no such sector.");

                continue;
            }

            foreach ($rows as [$title, $price, $file, $about]) {
                $src = storage_path("app/private/demo-src/cells/{$file}.png");
                $path = null;

                if (is_file($src) && ($img = @imagecreatefrompng($src))) {
                    ob_start();
                    imagejpeg($img, null, 90);
                    Storage::put($path = "listings/demo-more-{$file}.jpg", (string) ob_get_clean());
                }

                $data = ['description' => $about] + ($path ? ['photo_path' => $path] : []);

                if ($old = Listing::where('seller_id', $seller->id)->where('title', $title)->first()) {
                    $old->update($data);

                    continue;
                }

                Listing::create($data + [
                    'seller_id' => $seller->id, 'sector_id' => $sector->id, 'title' => $title, 'public_price' => $price, 'delivery_charge' => $price > 50000 ? 0 : 80,
                    'stock' => 12, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()->subMinutes($n--), 'cod_allowed' => $price < 100000,
                    'warranty_months' => $slug === 'electronics' ? 24 : null,
                ]);
                $made++;
            }
        }

        // The wide pictures at the top of three category pages, from the best of the new photographs, enlarged and saved as the sector's banner.
        foreach (['electronics' => 'e-laptop', 'clothing' => 'c-silk-saree', 'jewellery' => 'j-bridal-necklace', 'wood' => 'w-dining-table', 'beauty' => 'b-face-cream'] as $slug => $file) {
            $src = storage_path("app/private/demo-src/cells/{$file}.png");

            if (($sector = Sector::where('slug', $slug)->first()) && is_file($src) && ($img = @imagecreatefrompng($src)) && ($big = imagescale($img, 1306, 734, IMG_BICUBIC))) {
                ob_start();
                imagejpeg($big, null, 90);
                Storage::put($path = "sectors/demo-hero-{$slug}-2.jpg", (string) ob_get_clean());
                $sector->update(['hero_path' => $path]);
            }
        }

        $this->info("Added {$made} sample products.");

        return self::SUCCESS;
    }
}
