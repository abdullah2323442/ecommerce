<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\ActivityLog;
use App\Models\ArchivedRecord;
use App\Models\LoginEvent;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Archive\OldRecords;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 28, note 11: old data is stored separately, searchable but not burdening daily work. */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin, 'name' => 'Siam']);
    }

    private function log(string $action, string $when, array $changes = []): int
    {
        return DB::table('activity_logs')->insertGetId(['actor_id' => $this->admin->id, 'action' => $action, 'subject_type' => 'App\\Models\\User', 'subject_id' => 7, 'changes' => json_encode($changes), 'created_at' => $when]);
    }

    private function login(string $when, string $outcome = 'success'): int
    {
        return DB::table('login_events')->insertGetId(['user_id' => $this->admin->id, 'email' => 'old@example.com', 'ip' => '10.0.0.9', 'outcome' => $outcome, 'is_suspicious' => false, 'created_at' => $when]);
    }

    private function notice(string $when, bool $read): int
    {
        return DB::table('notices')->insertGetId(['user_id' => $this->admin->id, 'kind' => 'tier_up', 'body' => 'You moved up to tier A', 'read_at' => $read ? $when : null, 'created_at' => $when, 'updated_at' => $when]);
    }

    public function test_old_records_move_whole_and_recent_ones_stay(): void
    {
        $old = $this->log('customer.tier_changed', now()->subMonths(14)->toDateTimeString(), ['from' => 1, 'to' => 2]);
        $fresh = $this->log('customer.tier_changed', now()->subMonths(2)->toDateTimeString());
        $oldLogin = $this->login(now()->subMonths(13)->toDateTimeString(), 'failed');
        $freshLogin = $this->login(now()->subDays(3)->toDateTimeString());

        $moved = app(OldRecords::class)->run();

        $this->assertSame(['activity' => 1, 'login' => 1, 'notice' => 0], $moved);
        $this->assertNull(ActivityLog::find($old));
        $this->assertNotNull(ActivityLog::find($fresh));
        $this->assertNull(LoginEvent::find($oldLogin));
        $this->assertNotNull(LoginEvent::find($freshLogin));

        $kept = ArchivedRecord::where(['source' => 'activity', 'source_id' => $old])->firstOrFail();
        $this->assertSame('customer.tier_changed', $kept->payload['action']);
        $this->assertStringContainsString('Siam customer.tier_changed User #7', $kept->summary);
        $this->assertStringContainsString('failed old@example.com', ArchivedRecord::where('source', 'login')->value('summary'));
    }

    public function test_only_read_notices_move_an_unread_one_stays_however_old(): void
    {
        $read = $this->notice(now()->subMonths(20)->toDateTimeString(), true);
        $unread = $this->notice(now()->subMonths(20)->toDateTimeString(), false);

        app(OldRecords::class)->run();

        $this->assertNull(Notice::find($read));
        $this->assertNotNull(Notice::find($unread));
        $this->assertSame(1, ArchivedRecord::where('source', 'notice')->count());
    }

    public function test_it_is_off_when_the_months_are_zero_and_the_age_is_the_owners_to_set(): void
    {
        $this->log('x.y', now()->subMonths(30)->toDateTimeString());

        Setting::write('archive.after_months', 0);
        $this->assertSame(0, array_sum(app(OldRecords::class)->run()));
        $this->assertSame(1, ActivityLog::count());

        Setting::write('archive.after_months', 36);
        $this->assertSame(0, array_sum(app(OldRecords::class)->run()));

        Setting::write('archive.after_months', 24);
        $this->assertSame(1, array_sum(app(OldRecords::class)->run()));
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_running_it_twice_never_copies_a_record_twice(): void
    {
        $this->log('x.y', now()->subMonths(30)->toDateTimeString());

        app(OldRecords::class)->run();
        app(OldRecords::class)->run();

        $this->assertSame(1, ArchivedRecord::count());
    }

    public function test_a_record_already_copied_but_left_behind_still_leaves_the_busy_table(): void
    {
        $id = $this->log('x.y', now()->subMonths(30)->toDateTimeString());
        ArchivedRecord::create(['source' => 'activity', 'source_id' => $id, 'occurred_at' => now()->subMonths(30), 'summary' => 'x.y', 'payload' => ['id' => $id], 'archived_at' => now()]);

        app(OldRecords::class)->run();

        $this->assertSame(0, ActivityLog::count());
        $this->assertSame(1, ArchivedRecord::count());
    }

    public function test_a_large_pile_moves_in_pieces(): void
    {
        foreach (range(1, 1100) as $i) {
            $rows[] = ['actor_id' => $this->admin->id, 'action' => 'bulk.'.$i, 'created_at' => now()->subMonths(40)->toDateTimeString()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        $this->assertSame(1100, app(OldRecords::class)->run()['activity']);
        $this->assertSame(0, ActivityLog::count());
        $this->assertSame(1100, ArchivedRecord::count());
    }

    public function test_the_archive_is_searched_by_every_word_kind_and_date(): void
    {
        $this->log('customer.tier_changed', now()->subMonths(14)->toDateTimeString());
        $this->log('stock.received', now()->subMonths(26)->toDateTimeString());
        $this->login(now()->subMonths(15)->toDateTimeString());
        app(OldRecords::class)->run();
        $this->actingAs($this->admin);

        $this->get('/admin/archive')->assertInertia(fn (Assert $p) => $p->where('rows.total', 3)->where('counts.activity', 2)->where('counts.login', 1)->where('months', 12));
        $this->get('/admin/archive?q=tier+siam')->assertInertia(fn (Assert $p) => $p->where('rows.total', 1)->where('rows.data.0.source', 'activity'));
        $this->get('/admin/archive?source=login')->assertInertia(fn (Assert $p) => $p->where('rows.total', 1));
        $this->get('/admin/archive?to='.now()->subMonths(20)->toDateString())->assertInertia(fn (Assert $p) => $p->where('rows.total', 1)->where('rows.data.0.summary', fn ($s) => str_contains($s, 'stock.received')));
        $this->get('/admin/archive?q=nothing+like+this')->assertInertia(fn (Assert $p) => $p->where('rows.total', 0));
        $this->get('/admin/archive?q=100%25')->assertInertia(fn (Assert $p) => $p->where('rows.total', 0)); // a percent sign is a character here, not a wildcard
    }

    public function test_the_owner_can_move_old_records_now_and_is_told_how_many(): void
    {
        $this->log('x.y', now()->subMonths(30)->toDateTimeString());
        $this->log('x.z', now()->subMonths(30)->toDateTimeString());

        $this->actingAs($this->admin)->post('/admin/archive/run')->assertSessionHas('status', '2 old records moved to the archive.');
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_only_those_who_manage_accounts_can_open_it(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->get('/admin/archive')->assertRedirect('/login');
        foreach ([$staff, $shop] as $who) {
            $this->actingAs($who)->get('/admin/archive')->assertRedirect('/');
            $this->actingAs($who)->post('/admin/archive/run')->assertRedirect('/');
        }
    }

    public function test_the_nightly_job_is_scheduled(): void
    {
        $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();

        $this->assertContains('archive:run', $names);
    }
}
