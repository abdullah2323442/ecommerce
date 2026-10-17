<?php

namespace App\Console\Commands;

use App\Models\Sector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Banner pictures for a development shop: one wide picture and one line for each kind of business, put together from the two halves
 * kept in storage/app/private/demo-src/tiles. The owner replaces them in Admin > Market. Safe to run again.
 */
class SeedDemoHeroes extends Command
{
    protected $signature = 'demo:heroes {--force : Allow it on a production server}';

    protected $description = 'Add a sample banner and line to each kind of business (development only)';

    /** sector slug => [left half, right half, line, line in Bangla] */
    private const HEROES = [
        'wood' => ['ibxc9v', '5rdu6m', 'Hand-finished furniture, made to last a lifetime.', 'হাতে তৈরি আসবাব, সারা জীবন টেকার মতো।'],
        'jewellery' => ['di9bj1', 'f0qtoj', 'Pearls and gold, chosen with care.', 'যত্নে বাছাই করা মুক্তা ও সোনা।'],
        'electronics' => ['rlglpz', 'vzq0pv', 'Everyday gadgets with a proper warranty.', 'নিয়মিত ওয়ারেন্টিসহ প্রতিদিনের গ্যাজেট।'],
        'books' => ['n9uuno', 's3wp2p', 'Books and stationery for slow afternoons.', 'ধীরে কাটানো বিকেলের বই ও স্টেশনারি।'],
        'home' => ['te7i6w', 'pbstlh', 'Quiet pieces that make a house feel like home.', 'ঘরকে আপন করে তোলার শান্ত জিনিস।'],
        'beauty' => ['1a4hqv', 'dlk0j0', 'Gentle skincare, made from simple ingredients.', 'সহজ উপাদানে তৈরি কোমল ত্বকের যত্ন।'],
        'grocery' => ['du6ttx', 'gu7aiy', 'Honey, tea and pantry staples from small producers.', 'ছোট উৎপাদকদের মধু, চা ও ঘরের দরকারি খাবার।'],
        'clothing' => ['xr7rxd', 'chht7x', 'Linen and cotton that breathe in the heat.', 'গরমে আরাম দেয় লিনেন ও সুতি।'],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to add sample banners on a production server. Use --force if you really mean it.');

            return self::FAILURE;
        }

        $done = 0;

        foreach (self::HEROES as $slug => [$a, $b, $line, $lineBn]) {
            $sector = Sector::where('slug', $slug)->first();
            $left = storage_path("app/private/demo-src/tiles/{$a}.png");
            $right = storage_path("app/private/demo-src/tiles/{$b}.png");

            if (! $sector || ! is_file($left) || ! is_file($right)) {
                $this->warn("Skipped {$slug}: no sector or no picture.");

                continue;
            }

            // Each half is 800 wide and shows part of a 1283 wide banner; the right half starts 483 in.
            $canvas = imagecreatetruecolor(1283, 716);
            imagecopy($canvas, imagecreatefrompng($left), 0, 0, 0, 0, 800, 716);
            imagecopy($canvas, imagecreatefrompng($right), 483, 0, 0, 0, 800, 716);

            ob_start();
            imagejpeg($canvas, null, 88);
            Storage::put($path = "sectors/demo-hero-{$slug}.jpg", (string) ob_get_clean());

            $sector->update(['hero_path' => $path, 'tagline' => $line, 'tagline_bn' => $lineBn]);
            $done++;
        }

        $this->info("Added banners to {$done} kinds of business.");

        return self::SUCCESS;
    }
}
