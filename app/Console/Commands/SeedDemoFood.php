<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\FoodItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sample restaurants and menus for a development shop, so the food pages have something to show: three restaurants, each with a few dishes in
 * groups (Chicken, Burgers, Rolls, Sides, Biryani) and a photograph from storage/app/private/demo-src when it is there. The prices are invented.
 * It refuses to run on a production server and is safe to run again: a restaurant or dish already there is updated, never doubled.
 */
class SeedDemoFood extends Command
{
    protected $signature = 'demo:food {--force : Allow it on a production server}';

    protected $description = 'Add sample restaurants and menus (development only)';

    /** key => [name, description, area, delivers to, [dish rows]]; a dish row is [name, description, group, price, photo file or null, minutes]. */
    private const PLACES = [
        'crispy-house' => ['Crispy House', 'Fried chicken, burgers and rolls, cooked fresh and fast.', 'Mirpur', ['Mirpur', 'Dhanmondi', 'Uttara', 'Mohammadpur'], [
            ['Crispy Chicken Bucket', 'Eight pieces of golden fried chicken, crisp outside and juicy inside.', 'Chicken', 1250, 'food-bucket', 20],
            ['Hot Wings, 6 pieces', 'Spicy wings with a crunchy coat.', 'Chicken', 290, 'food-bucket', 15],
            ['Crispy Chicken Burger', 'A crunchy chicken fillet with lettuce, tomato and mayo in a toasted bun.', 'Burgers', 320, 'food-burger', 12],
            ['Double Cheese Burger', 'Two fillets, two slices of melted cheese, fresh vegetables.', 'Burgers', 420, 'food-burger', 12],
            ['Grilled Chicken Roll', 'Smoky chicken in a flaky paratha with onion and green chutney.', 'Rolls', 220, 'food-roll', 10],
            ['French Fries', 'Hot, salted and crisp, with ketchup.', 'Sides', 150, 'food-fries', 8],
            ['Family Combo', 'A bucket, two burgers and a large fries for four.', 'Combos', 1850, 'food-bucket', 25],
        ]],
        'dhakaiya-biryani' => ['Dhakaiya Biryani House', 'Kacchi biryani and polao the old Dhaka way, cooked in brass handis.', 'Dhanmondi', ['Dhanmondi', 'Gulshan', 'Banani', 'Old Dhaka'], [
            ['Kacchi Biryani, half', 'Tender mutton and fragrant rice with a boiled egg and fried onion.', 'Biryani', 380, 'food-biryani', 20],
            ['Kacchi Biryani, full', 'The full handi, enough for two.', 'Biryani', 720, 'food-biryani', 20],
            ['Morog Polao', 'Rich chicken polao with a potato and a lemon.', 'Biryani', 340, 'food-biryani', 18],
            ['Kebab Roll', 'Charcoal kebab pieces in a soft paratha.', 'Rolls', 240, 'food-roll', 12],
        ]],
        'roll-and-wrap' => ['Roll and Wrap Co', 'Street rolls and wraps, Dhaka style, made to order.', 'Gulshan', ['Gulshan', 'Banani', 'Uttara', 'Baridhara'], [
            ['Chicken Kebab Roll', 'Two rolls with chutney and sliced onion.', 'Rolls', 260, 'food-roll', 10],
            ['Beef Tikka Roll', 'Spiced beef tikka, crunchy salad, sharp chutney.', 'Rolls', 290, 'food-roll', 12],
            ['Loaded Fries', 'Fries with a chicken topping and cheese sauce.', 'Sides', 210, 'food-fries', 10],
        ]],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to add sample restaurants on a production server. Use --force if you really mean it.');

            return self::FAILURE;
        }

        $dishes = 0;

        foreach (self::PLACES as $key => [$name, $about, $area, $areas, $menu]) {
            $owner = User::firstOrCreate(
                ['email' => "demo-{$key}@aquabazar.test"],
                ['name' => $name.' owner', 'password' => Hash::make(Str::random(32)), 'account_type' => AccountType::Public],
            );
            $photo = $this->picture($menu[0][4] ?? null, "food/demo-{$key}.jpg");

            $r = Restaurant::updateOrCreate(['owner_id' => $owner->id], [
                'name' => $name, 'description' => $about, 'area' => $area, 'areas' => $areas, 'status' => 'active', 'is_open' => true, 'opens_at' => '10:00', 'closes_at' => '23:30',
                'eat_there' => true, 'address' => $area.', Dhaka',
            ] + ($photo ? ['photo_path' => $photo] : []));

            foreach ($menu as $i => [$dish, $text, $group, $price, $file, $minutes]) {
                $path = $this->picture($file, 'food/demo-'.Str::slug($key.'-'.$dish).'.jpg');
                FoodItem::updateOrCreate(['restaurant_id' => $r->id, 'name' => $dish], [
                    'description' => $text, 'category' => $group, 'price' => $price, 'cook_minutes' => $minutes, 'available' => true, 'sort' => $i + 1,
                ] + ($path ? ['photo_path' => $path] : []));
                $dishes++;
            }
        }

        $this->info('Added or updated '.count(self::PLACES)." restaurants with {$dishes} dishes.");

        return self::SUCCESS;
    }

    /** Store a demo picture as a JPEG and return its path, or null when the source file is not there. */
    private function picture(?string $file, string $to): ?string
    {
        $src = $file ? storage_path("app/private/demo-src/{$file}.png") : null;

        if (! $src || ! is_file($src) || ! ($img = @imagecreatefrompng($src))) {
            return null;
        }

        ob_start();
        imagejpeg($img, null, 88);
        Storage::put($to, (string) ob_get_clean());

        return $to;
    }
}
