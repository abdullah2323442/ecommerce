<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Listing;
use App\Models\MarketOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sample order history for the last thirty days, so the admin dashboard's charts have something to draw while the shop is still new. Development only.
 * The orders are plainly marked (a "demo" label on shop orders, the "demo" note on market orders) and removed again with --clear.
 */
class SeedDemoOrders extends Command
{
    protected $signature = 'demo:orders {--clear : Remove the sample orders instead} {--force : Allow it on a production server}';

    protected $description = 'Add (or remove) sample orders over the last 30 days for the dashboard (development only)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to touch orders on a production server. Use --force if you really mean it.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $a = MarketOrder::where('note', 'demo')->delete();
            $b = Invoice::where('label', 'demo')->delete();
            $this->info("Removed {$a} market and {$b} shop sample orders.");

            return self::SUCCESS;
        }

        $listings = Listing::whereNotNull('photo_path')->with('sector')->get();

        if ($listings->isEmpty()) {
            $this->error('There are no products yet. Run php artisan demo:catalog first.');

            return self::FAILURE;
        }

        mt_srand(20261017);
        $market = $shop = 0;

        for ($d = 29; $d >= 0; $d--) {
            // A gentle rise over the month, with a bump at the weekend.
            $wave = 1 + (29 - $d) / 29 * 0.8 + (in_array(now()->subDays($d)->dayOfWeek, [5, 6], true) ? 0.6 : 0);

            for ($i = 0, $n = (int) round(mt_rand(2, 5) * $wave); $i < $n; $i++) {
                $l = $listings[mt_rand(0, $listings->count() - 1)];
                $qty = mt_rand(1, 3);
                $unit = round((float) $l->public_price * 1.02, 2);
                $at = now()->subDays($d)->setTime(mt_rand(9, 21), mt_rand(0, 59));
                $roll = mt_rand(1, 100);
                $status = $d > 3 ? ($roll <= 82 ? 'delivered' : ($roll <= 94 ? 'shipped' : 'cancelled')) : ($roll <= 40 ? 'placed' : ($roll <= 75 ? 'accepted' : 'shipped'));

                MarketOrder::insert([
                    'public_token' => Str::random(40), 'listing_id' => $l->id, 'seller_id' => $l->seller_id, 'title' => $l->title, 'unit' => 'piece', 'quantity' => $qty, 'unit_price' => $unit, 'seller_price' => (float) $l->public_price,
                    'commission' => round($qty * ($unit - (float) $l->public_price), 2), 'delivery_charge' => 80, 'total' => round($qty * $unit + 80, 2), 'audience' => 'public', 'status' => $status,
                    'ship_to' => json_encode(['name' => 'Demo', 'phone' => '01700000000']), 'note' => 'demo', 'pay_by' => mt_rand(1, 100) <= 35 ? 'online' : 'cod', 'paid_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                ]);
                $market++;
            }

            for ($i = 0, $n = (int) round(mt_rand(1, 4) * $wave); $i < $n; $i++) {
                $at = now()->subDays($d)->setTime(mt_rand(9, 21), mt_rand(0, 59));
                $roll = mt_rand(1, 100);
                $cancelled = $roll > 93;
                Invoice::insert([
                    'kind' => 'retail', 'channel' => mt_rand(1, 100) <= 60 ? 'online' : 'counter', 'status' => $d > 2 ? 'final' : ($roll <= 50 ? 'placed' : 'verified'), 'label' => 'demo', 'total' => mt_rand(120, 2600),
                    'payment_method' => mt_rand(1, 100) <= 30 ? 'online' : 'cash', 'cancelled_at' => $cancelled ? $at : null, 'created_at' => $at, 'updated_at' => $at,
                ]);
                $shop++;
            }
        }

        $this->info("Added {$market} market and {$shop} shop sample orders.");

        return self::SUCCESS;
    }
}
