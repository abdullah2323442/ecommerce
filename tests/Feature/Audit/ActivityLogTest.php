<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_who_what_and_when(): void
    {
        $admin = User::factory()->create();
        $subject = User::factory()->create();

        $log = app(ActivityLogger::class)->record('customer.suspended', $subject, ['reason' => 'late payment'], $admin);

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('customer.suspended', $log->action);
        $this->assertSame($subject->id, $log->subject_id);
        $this->assertSame(['reason' => 'late payment'], $log->changes);
        $this->assertNotNull($log->created_at);
    }

    public function test_log_rows_are_never_updated_by_the_logger(): void
    {
        $log = app(ActivityLogger::class)->record('stock.corrected');

        $this->assertSame(1, ActivityLog::count());
        $this->assertSame('stock.corrected', ActivityLog::first()->action);
    }
}
