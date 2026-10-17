<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\GamePackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Game top-up sample data for a development shop: the games and packs a Bangladeshi player looks for, with prices in taka that follow what
 * Codashop Bangladesh (codashop.com/en-bd) listed on 17 October 2026, so the screens show believable money. These are sample prices, not an offer:
 * the owner sets the real ones in Admin > Game top-ups. Cover pictures come from storage/app/private/demo-src when they are there.
 * Safe to run again: a game and a pack already there are updated, never doubled.
 */
class SeedDemoGames extends Command
{
    protected $signature = 'demo:games {--force : Allow it on a production server}';

    protected $description = 'Add sample game top-ups with Codashop-like prices (development only)';

    /** slug => [name, id label, zone label or null, cover file, [[pack, price in taka]...]] */
    private const GAMES = [
        'mobile-legends' => ['Mobile Legends: Bang Bang', 'User ID', 'Zone ID', 'cover-moba', [['33 Diamonds', 50], ['66 Diamonds', 100], ['131 Diamonds', 200], ['325 Diamonds', 500], ['684 Diamonds', 1000], ['1369 Diamonds', 2000], ['2089 Diamonds', 3000]]],
        'pubg-mobile' => ['PUBG Mobile', 'Player ID', null, 'cover-battle', [['60 UC', 147], ['325 UC', 735], ['660 UC', 1470], ['1800 UC', 3675]]],
        '8-ball-pool' => ['8 Ball Pool', 'Unique ID', null, 'cover-pool', [['20 Cash', 79], ['50 Cash', 209], ['110 Cash', 419], ['20,000 Coins', 79], ['52,000 Coins', 209], ['112,000 Coins', 419]]],
        'ea-sports-fc-mobile' => ['EA SPORTS FC Mobile', 'Player ID', null, 'cover-football', [['40 FC Points', 36], ['100 FC Points', 125], ['520 FC Points', 575], ['1070 FC Points', 1175], ['2200 FC Points', 2350]]],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to add sample games on a production server. Use --force if you really mean it.');

            return self::FAILURE;
        }

        $sort = 0;
        $packs = 0;

        foreach (self::GAMES as $slug => [$name, $idLabel, $zone, $cover, $list]) {
            $photo = null;
            $src = storage_path("app/private/demo-src/{$cover}.png");

            if (is_file($src) && ($img = @imagecreatefrompng($src))) {
                ob_start();
                imagejpeg($img, null, 88);
                Storage::put($photo = "games/demo-{$slug}.jpg", (string) ob_get_clean());
            }

            $game = Game::updateOrCreate(['slug' => $slug], ['name' => $name, 'id_label' => $idLabel, 'needs_zone' => $zone !== null, 'zone_label' => $zone, 'is_active' => true, 'sort' => ++$sort] + ($photo ? ['photo_path' => $photo] : []));

            foreach ($list as $i => [$pack, $price]) {
                GamePackage::updateOrCreate(['game_id' => $game->id, 'name' => $pack], ['price' => $price, 'is_active' => true, 'sort' => $i + 1]);
                $packs++;
            }
        }

        $this->info('Added or updated '.count(self::GAMES)." games with {$packs} packs.");

        return self::SUCCESS;
    }
}
