<?php

namespace App\Providers;

use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqlDumper;
use App\Services\Gaming\ManualTopUp;
use App\Services\Gaming\PlayerVerifier;
use App\Services\Gaming\TopUpProvider;
use App\Services\Gaming\UnavailableVerifier;
use App\Services\Messages\LogSmsGateway;
use App\Services\Messages\SmsGateway;
use App\Services\Security\LoginMonitor;
use App\Services\Security\SessionLimiter;
use App\Support\BraceTranslator;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fill `{n}` placeholders as well as `:name` ones, on the server just as t() does in the browser.
        $this->app->extend('translator', function ($translator, $app) {
            $new = new BraceTranslator($app['translation.loader'], $app->getLocale());
            $new->setFallback($app->getFallbackLocale());

            return $new;
        });

        $this->app->bind(DatabaseDumper::class, MysqlDumper::class);
        // The text service follows what the owner set up in Admin > Connections (a live one sends; none, or a test one, only logs).
        // Texts leave the buyer's request and follow from the queue; a code someone is waiting to type is the exception and goes at once.
        $this->app->bind(SmsGateway::class, \App\Services\Messages\QueuedSmsGateway::class);
        $this->app->when(\App\Services\Security\PhoneVerifier::class)->needs(SmsGateway::class)->give(\App\Services\Messages\ConfiguredSmsGateway::class);
        // Game top-up (spec ch 18): no provider is chosen yet, so no name is looked up and the top-up is done by hand.
        $this->app->bind(PlayerVerifier::class, UnavailableVerifier::class);
        $this->app->bind(TopUpProvider::class, ManualTopUp::class);

        $this->app->bind(LoginMonitor::class, fn () => new LoginMonitor(
            deviceLimit: (int) config('security.device_limit', 3),
        ));
    }

    public function boot(): void
    {
        Route::model('return', SaleReturn::class);

        // The super admin passes every gate; everyone else needs the matching permission on their role (spec ch 26).
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        foreach ([
            'manage-customers' => 'customers',   // customers, tiers, rates (wholesale manager)
            'use-counter' => 'counter',
            'manage-stock' => 'stock',           // purchase prices and stock numbers
            'manage-offers' => 'offers',
            'approve-returns' => 'returns_approve',
            'decide-approvals' => 'approvals',
            'view-reports' => 'reports',         // sales and profit
            'manage-accounts' => 'accounts',     // staff, roles, activity log
            'make-deliveries' => 'deliveries',   // our own delivery people
        ] as $gate => $permission) {
            Gate::define($gate, fn (User $user) => $user->hasPermission($permission));
        }

        // Online orders, prescriptions and recording returns are narrower than the whole counter.
        Gate::define('handle-orders', fn (User $user) => $user->hasPermission('orders'));
        Gate::define('record-returns', fn (User $user) => $user->hasPermission('returns'));

        Event::listen(Login::class, function (Login $event) {
            app(LoginMonitor::class)->recordSuccess($event->user, request());
            app(SessionLimiter::class)->enforce($event->user);
            \App\Services\Sales\CartStore::adopt($event->user);
        });

        Event::listen(Failed::class, function (Failed $event) {
            app(LoginMonitor::class)->recordFailure(
                $event->user,
                (string) ($event->credentials['email'] ?? ''),
                request(),
            );
        });
    }
}
