<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    private string $sql = '';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup-test');
        config(['backup.disk' => 'backup-test', 'backup.directory' => 'backups', 'backup.retention_days' => 30]);

        $this->sql = implode("\n", array_map(
            fn ($t) => "CREATE TABLE `{$t}` (id int);",
            ['users', 'medicines', 'companies', 'activity_logs'],
        ));

        // Read $this->sql when the dumper is resolved, so a test can change the dump content first.
        $this->app->bind(DatabaseDumper::class, fn () => new class($this->sql) implements DatabaseDumper
        {
            public function __construct(private string $sql) {}

            public function dumpTo(string $path): void
            {
                file_put_contents($path, $this->sql);
            }
        });
    }

    public function test_creates_a_compressed_backup_on_the_configured_disk(): void
    {
        $name = app(BackupService::class)->run();

        Storage::disk('backup-test')->assertExists("backups/{$name}");
        $this->assertStringEndsWith('.sql.gz', $name);
    }

    public function test_a_fresh_backup_verifies(): void
    {
        app(BackupService::class)->run();

        $check = app(BackupService::class)->verifyLatest();

        $this->assertGreaterThan(0, $check['bytes']);
    }

    public function test_a_backup_missing_a_core_table_fails_verification(): void
    {
        $this->sql = 'CREATE TABLE `users` (id int);';
        app(BackupService::class)->run();

        $this->expectExceptionMessage('missing table');
        app(BackupService::class)->verifyLatest();
    }

    public function test_verification_with_no_backups_fails(): void
    {
        $this->expectExceptionMessage('No backups found');
        app(BackupService::class)->verifyLatest();
    }

    public function test_prune_removes_only_backups_past_the_retention_window(): void
    {
        $disk = Storage::disk('backup-test');
        $disk->put('backups/db-2020-01-01_000000.sql.gz', 'old');
        $disk->put('backups/db-2099-01-01_000000.sql.gz', 'new');
        touch($disk->path('backups/db-2020-01-01_000000.sql.gz'), now()->subDays(45)->getTimestamp());

        $deleted = app(BackupService::class)->prune();

        $this->assertSame(['db-2020-01-01_000000.sql.gz'], $deleted);
        $disk->assertMissing('backups/db-2020-01-01_000000.sql.gz');
        $disk->assertExists('backups/db-2099-01-01_000000.sql.gz');
    }

    public function test_prune_keeps_a_backup_exactly_inside_the_window(): void
    {
        $disk = Storage::disk('backup-test');
        $disk->put('backups/db-2026-01-01_000000.sql.gz', 'x');
        touch($disk->path('backups/db-2026-01-01_000000.sql.gz'), now()->subDays(29)->getTimestamp());

        $this->assertSame([], app(BackupService::class)->prune());
    }

    public function test_stale_detection(): void
    {
        $this->assertTrue(app(BackupService::class)->isStale());

        app(BackupService::class)->run();

        $this->assertFalse(app(BackupService::class)->isStale());
    }

    public function test_the_backup_command_succeeds_and_reports(): void
    {
        $this->artisan('backup:run', ['--verify' => true])->assertSuccessful();
    }
}
