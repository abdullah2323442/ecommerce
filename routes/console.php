<?php

use App\Models\User;
use App\Services\Audit\Bin;
use App\Services\Help\HelpBoard;
use App\Services\Custom\Craftsmen;
use App\Services\Custom\CustomChat;
use App\Services\Custom\CustomOrders;
use App\Services\Food\FoodOrders;
use App\Services\Gaming\GameTopUp;
use App\Services\Market\MarketCases;
use App\Services\Market\MarketOrders;
use App\Services\Market\Statements;
use App\Services\Mpo\MpoBidding;
use App\Services\Sales\BuyAgain;
use App\Services\Sales\Dispatching;
use App\Services\Sales\OrderHabits;
use App\Services\Sales\PublicOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Spec ch 28 note 7: nightly backup, 30 days kept, integrity-checked, off the server.
// Run at 02:00 Bangladesh time; the night is quiet and orders are packed overnight, so keep it light.
Schedule::command('backup:run --verify')
    ->dailyAt('02:00')
    ->timezone('Asia/Dhaka')
    ->onOneServer()
    ->withoutOverlapping();

// Spec ch 26: a suspension for a set period ends by itself; this tidies the flag so lists agree.
Schedule::call(fn () => User::whereNotNull('suspended_at')->whereNotNull('suspended_until')->where('suspended_until', '<', now())->update(['suspended_at' => null, 'suspended_until' => null, 'suspend_reason' => null]))->hourly()->name('accounts:lift-suspensions');

// Spec ch 10: ask an MPO whose product is not selling to raise the percentage, and cancel an order that did not arrive in time.
Schedule::call(function () {
    app(MpoBidding::class)->tellStale();
    app(MpoBidding::class)->expireOrders();
})->daily()->name('mpo:housekeeping');

// Spec ch 14: on the first of each month the statement for the month before goes to each seller; one nobody answers in time stops selling.
Schedule::call(fn () => app(Statements::class)->generate())->monthlyOn(1, '09:00')->timezone('Asia/Dhaka')->name('market:statements');
Schedule::call(fn () => app(Statements::class)->suspendSilent())->daily()->name('market:suspend-silent');

// Spec ch 14: a sent market order nobody answers for a few days is taken as received.
Schedule::call(fn () => app(MarketOrders::class)->autoConfirm())->daily()->name('market:auto-confirm');

// Spec ch 14: a complaint the seller leaves unanswered comes to us to decide.
Schedule::call(fn () => app(MarketCases::class)->escalateSilent())->daily()->name('market:escalate-silent');

// Spec ch 16: finished made-to-order work nobody collects cannot be sold to anyone else, so the buyer is reminded.
Schedule::call(fn () => app(CustomOrders::class)->remindPickup())->dailyAt('10:00')->timezone('Asia/Dhaka')->name('custom:remind-pickup');

// Spec ch 16.7: a job past its time tells us and the buyer; no update for three days tells us and the craftsman.
Schedule::call(fn () => app(Craftsmen::class)->watch())->dailyAt('09:30')->timezone('Asia/Dhaka')->name('custom:watch');

// Spec ch 23: a buyer's chat message nobody answers in time comes to us.
Schedule::call(fn () => app(CustomChat::class)->watch())->hourly()->name('custom:chat-watch');

// Spec ch 17: a restaurant that never confirms - the buyer is asked whether to cancel.
Schedule::call(fn () => app(FoodOrders::class)->nudgeUnconfirmed())->everyFiveMinutes()->name('food:unconfirmed');

// Spec ch 18: a top-up that is paid and late - the buyer is told and can cancel for a refund.
Schedule::call(fn () => app(GameTopUp::class)->watchLate())->everyFiveMinutes()->name('gaming:late');

// Spec ch 19: a Help post open for a month is asked about; no answer and it closes.
Schedule::call(fn () => app(HelpBoard::class)->sweep())->daily()->name('help:sweep');

// Spec ch 25: what was deleted stays 30 days in the bin, then it is gone for good.
Schedule::call(fn () => app(Bin::class)->purge())->daily()->name('bin:purge');

// Spec ch 20: an order sent and never answered confirms itself after a few days, unless the buyer said it did not come.
Schedule::call(fn () => app(Dispatching::class)->autoConfirm())->daily()->name('orders:auto-confirm');

// Spec ch 13: an order nobody answered is cancelled after a while and the buyer is told.
Schedule::call(fn () => app(PublicOrders::class)->cancelStale())->hourly()->name('orders:cancel-stale');

// Spec ch 7: each shop is nudged at the time of day it usually orders. Bangladesh time, every ten minutes.
Schedule::call(fn () => app(OrderHabits::class)->remind(now('Asia/Dhaka'), 15))->everyTenMinutes()->name('shops:order-reminders');

// Spec ch 13: "buy again", a nudge to someone who buys the same medicine about every month. Mornings, Bangladesh time.
// Spec ch 27 (Phase 2): a signed-in buyer who left things in the cart for a day is reminded once.
Schedule::call(fn () => app(\App\Services\Sales\PublicCart::class)->remind())->hourly()->name('carts:remind');

// Spec ch 13 (Phase 2): tell a buyer when a product on their wishlist got cheaper.
Schedule::call(fn () => app(\App\Services\Market\Wishlist::class)->dropAlerts())->dailyAt('11:00')->timezone('Asia/Dhaka')->name('wishlist:drops');

Schedule::call(fn () => app(BuyAgain::class)->remind(now('Asia/Dhaka')))->dailyAt('10:00')->timezone('Asia/Dhaka')->name('shops:buy-again');

// Spec 6.2: a "Need admin approval" request nobody answers cancels itself.
Schedule::command('approvals:expire')->everyMinute()->withoutOverlapping();

// Monthly reminder check that backups are fresh and readable (a real restore test is still manual).
Schedule::command('backup:verify')
    ->monthlyOn(1, '09:00')
    ->timezone('Asia/Dhaka');

// Spec ch 23: a question a seller leaves too long comes to us.
Schedule::call(fn () => app(\App\Services\Market\ListingQuestions::class)->escalate())->everyFifteenMinutes()->name('market:questions');

// Spec ch 13: a price offer nobody answers is cancelled and the buyer is told.
Schedule::call(fn () => app(\App\Services\Market\PriceOffers::class)->expire())->everyFifteenMinutes()->name('market:offers');

// Spec ch 20: remind a buyer who has not collected, and cancel the order if nobody comes.
Schedule::call(fn () => app(\App\Services\Market\MarketOrders::class)->sweepPickups())->hourly()->name('market:pickups');
// Texts and emails wait in a queue so a slow provider never holds up an order. Where no worker is kept running, this empties the queue every minute.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=4')->everyMinute()->withoutOverlapping(5)->name('queue:drain');
Schedule::call(fn () => app(\App\Services\Market\MarketOrders::class)->expireUnpaid())->hourly()->name('market:expire-unpaid');

// Spec ch 20: ask the courier companies where the parcels are (their own messages usually come first).
Schedule::call(fn () => app(\App\Services\Couriers\CourierBooking::class)->sweep())->everyThirtyMinutes()->name('couriers:sync');
