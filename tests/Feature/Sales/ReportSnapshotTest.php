<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\ReportSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\ReportPeriod;
use App\Services\Sales\ReportSnapshots;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 06 and 25: the monthly report starts on the day the owner chooses (item 596), and is prepared overnight so it opens instantly (item 551). */
class ReportSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function memo(string $on, float $total, array $over = []): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'kind' => 'retail', 'channel' => 'counter', 'status' => 'final', 'number' => 'M2026-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'total' => $total, 'paid_amount' => $total, 'finalised_at' => $on, 'completed_at' => $on, 'opened_at' => $on,
        ], $over));
    }

    public function test_a_month_starts_on_the_first_unless_the_owner_chooses_another_day(): void
    {
        $period = app(ReportPeriod::class);
        [$from, $to] = $period->range(Carbon::parse('2026-10-01'));
        $this->assertSame(['2026-10-01', '2026-10-31'], [$from->toDateString(), $to->toDateString()]);

        Setting::write('report.month_start_day', 5);
        [$from, $to] = $period->range(Carbon::parse('2026-10-01'));
        $this->assertSame(['2026-10-05', '2026-11-04'], [$from->toDateString(), $to->toDateString()]);
        $this->assertSame('2026-09', $period->labelFor(Carbon::parse('2026-10-03'))->format('Y-m'), 'before the 5th it is still last month');
        $this->assertSame('2026-10', $period->labelFor(Carbon::parse('2026-10-05'))->format('Y-m'));
        $this->assertSame('2026-12', $period->labelFor(Carbon::parse('2027-01-02'))->format('Y-m'));
    }

    public function test_a_silly_start_day_is_kept_within_the_month(): void
    {
        Setting::write('report.month_start_day', 31);
        $this->assertSame(28, app(ReportPeriod::class)->startDay());
        Setting::write('report.month_start_day', 0);
        $this->assertSame(1, app(ReportPeriod::class)->startDay());
    }

    public function test_the_report_counts_the_memos_inside_the_chosen_month_only(): void
    {
        Setting::write('report.month_start_day', 5);
        $this->memo('2026-10-03 10:00', 100); // September's, closed on the 4th of October
        $this->memo('2026-10-06 10:00', 200);
        $this->memo('2026-11-03 10:00', 400); // still October's
        $this->memo('2026-11-05 10:00', 800); // November's

        $this->actingAs($this->admin)->get('/admin/reports?month=2026-10')->assertInertia(fn (Assert $p) => $p
            ->where('totals.sales', 600)->where('totals.memos', 2)->where('previous.sales', 100)->where('range.from', '2026-10-05')->where('range.to', '2026-11-04')->where('startDay', 5));
    }

    public function test_the_report_opens_ready_made_and_says_when_it_was_prepared(): void
    {
        $this->memo('2026-10-10 10:00', 500);

        $this->actingAs($this->admin)->get('/admin/reports?month=2026-10')->assertInertia(fn (Assert $p) => $p->where('totals.sales', 500)->has('prepared_at'));
        $this->assertSame(1, ReportSnapshot::count());

        // The copy is read as it is, not worked out again: a memo made after it was prepared is not in it until it is prepared again.
        $this->memo('2026-10-11 10:00', 300);
        $this->get('/admin/reports?month=2026-10')->assertInertia(fn (Assert $p) => $p->where('totals.sales', 500));

        $this->post('/admin/reports/prepare?month=2026-10')->assertRedirect('/admin/reports?month=2026-10');
        $this->get('/admin/reports?month=2026-10')->assertInertia(fn (Assert $p) => $p->where('totals.sales', 800));
        $this->assertSame(1, ReportSnapshot::count());
    }

    public function test_a_copy_from_yesterday_is_made_again_for_the_month_in_progress_but_a_closed_month_is_kept(): void
    {
        $this->memo('2026-09-10 10:00', 100);
        $this->memo('2026-10-10 10:00', 500);
        $svc = app(ReportSnapshots::class);
        Carbon::setTestNow('2026-10-19 23:00');
        $svc->prepare(Carbon::parse('2026-10-01'));
        $svc->prepare(Carbon::parse('2026-09-01'));
        Carbon::setTestNow('2026-10-20 12:00');
        $this->memo('2026-10-12 10:00', 250);
        $this->memo('2026-09-12 10:00', 70);

        $this->actingAs($this->admin)->get('/admin/reports?month=2026-10')->assertInertia(fn (Assert $p) => $p->where('totals.sales', 750));
        $this->get('/admin/reports?month=2026-09')->assertInertia(fn (Assert $p) => $p->where('totals.sales', 100));
    }

    public function test_the_nightly_job_prepares_this_month_and_last_month(): void
    {
        $this->memo('2026-10-10 10:00', 500);
        $this->memo('2026-09-10 10:00', 100);

        $this->assertSame(2, app(ReportSnapshots::class)->prepareTonight());
        $this->assertSame(['2026-09', '2026-10'], ReportSnapshot::orderBy('period')->pluck('period')->all());
        $this->assertSame(500.0, (float) ReportSnapshot::where('period', '2026-10')->firstOrFail()->payload['totals']['sales']);

        $this->assertContains('reports:prepare', collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all());
    }

    public function test_a_corrected_memo_is_listed_apart_with_the_old_and_the_new_amount(): void
    {
        $old = $this->memo('2026-10-10 10:00', 1000, ['status' => 'cancelled', 'completed_at' => null]);
        $this->memo('2026-10-10 11:00', 1300, ['corrects_invoice_id' => $old->id]);

        $this->actingAs($this->admin)->get('/admin/reports?month=2026-10')->assertInertia(fn (Assert $p) => $p
            ->where('corrected.count', 1)->where('corrected.difference', 300)->where('corrected.items.0.replaces', $old->number)->where('corrected.items.0.was', 1000)->where('corrected.items.0.now', 1300)
            ->where('totals.sales', 1300));
    }

    public function test_only_those_who_may_see_the_reports_can_prepare_one(): void
    {
        $this->post('/admin/reports/prepare')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->post('/admin/reports/prepare')->assertRedirect('/');
        $this->assertSame(0, ReportSnapshot::count());
    }
}
