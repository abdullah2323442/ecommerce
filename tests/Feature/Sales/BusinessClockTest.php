<?php

namespace Tests\Feature\Sales;

use App\Services\Sales\OrderNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Spec ch 28: the server clock is Bangladesh time, so night hours, closed days and month ends fall where the owner means them.
 * The rest of the suite pins its clock to UTC (phpunit.xml) so its fixed times stay readable; this one runs the rules under Dhaka time.
 */
class BusinessClockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    private function dhaka(): void
    {
        config(['app.timezone' => 'Asia/Dhaka']);
        date_default_timezone_set('Asia/Dhaka');
    }

    public function test_the_shipped_default_is_bangladesh_time(): void
    {
        $default = (function () {
            $config = require base_path('config/app.php');

            return $config['timezone'];
        })();

        // The environment file may name another zone; the default a fresh install gets is Dhaka.
        $this->assertTrue($default === 'Asia/Dhaka' || env('APP_TIMEZONE') !== null);
        $this->assertStringContainsString("env('APP_TIMEZONE', 'Asia/Dhaka')", (string) file_get_contents(base_path('config/app.php')));
        $this->assertStringContainsString('APP_TIMEZONE=Asia/Dhaka', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_eleven_thirty_at_night_in_dhaka_is_night_and_noon_is_not(): void
    {
        $this->dhaka();
        $notice = app(OrderNotice::class);

        Carbon::setTestNow(Carbon::parse('2026-10-14 23:30:00', 'Asia/Dhaka'));
        $this->assertSame('night', $notice->at());

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'Asia/Dhaka'));
        $this->assertNull($notice->at());
    }

    public function test_friday_is_the_closed_day_by_the_bangladesh_calendar_not_utc(): void
    {
        $this->dhaka();

        // 01:00 on Friday in Dhaka is still Thursday evening in UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-16 01:00:00', 'Asia/Dhaka'));
        $this->assertSame('holiday', app(OrderNotice::class)->at());
    }

    public function test_the_month_turns_at_midnight_in_dhaka(): void
    {
        $this->dhaka();
        Carbon::setTestNow(Carbon::parse('2026-10-31 23:30:00', 'Asia/Dhaka'));
        $this->assertSame('2026-10', now()->format('Y-m'));

        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00', 'Asia/Dhaka'));
        $this->assertSame('2026-11', now()->format('Y-m'));
    }
}
