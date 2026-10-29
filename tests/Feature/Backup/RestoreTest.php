<?php

namespace Tests\Feature\Backup;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\DatabaseRestorer;
use App\Services\Backup\MysqlRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Spec ch 28 note 7: a monthly test that the newest backup really restores, into a scratch database that is always thrown away. */
class RestoreTest extends TestCase
{
    use RefreshDatabase;

    private const CORE = ['users', 'medicines', 'companies', 'activity_logs'];

    /** What the fake "database" holds after a restore, and what it was asked to do. */
    public array $restoredCounts = [];

    public array $liveCounts = [];

    public array $calls = [];

    public bool $failRestore = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup-test');
        config(['backup.disk' => 'backup-test', 'backup.directory' => 'backups', 'backup.required_tables' => self::CORE]);

        $this->restoredCounts = array_fill_keys(self::CORE, 3);
        $this->liveCounts = array_fill_keys(self::CORE, 3);

        $this->app->bind(DatabaseDumper::class, fn () => new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                file_put_contents($path, implode("\n", array_map(fn ($t) => "CREATE TABLE `{$t}` (id int);", ['users', 'medicines', 'companies', 'activity_logs', 'invoices'])));
            }
        });

        $test = $this;
        $this->app->bind(DatabaseRestorer::class, fn () => new class($test) implements DatabaseRestorer
        {
            public function __construct(private RestoreTest $t) {}

            public function restoreInto(string $database, string $sqlPath): void
            {
                $this->t->calls[] = ['restore', $database, str_contains((string) file_get_contents($sqlPath), 'CREATE TABLE')];

                if ($this->t->failRestore) {
                    throw new RuntimeException('The restore failed: syntax error near line 9');
                }
            }

            public function tableCounts(string $database): array
            {
                return $this->t->restoredCounts;
            }

            public function liveCounts(array $tables): array
            {
                return $this->t->liveCounts;
            }

            public function drop(string $database): void
            {
                $this->t->calls[] = ['drop', $database];
            }
        });

        app(BackupService::class)->run();
    }

    public function test_a_good_backup_restores_into_a_scratch_database_that_is_then_removed(): void
    {
        $this->restoredCounts['invoices'] = 0;

        $result = app(BackupService::class)->restoreTest();

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['problems']);
        $this->assertSame(5, $result['dumped']);
        $this->assertSame(5, $result['tables']);
        $this->assertSame(['restored' => 3, 'live' => 3], $result['core']['users']);

        $this->assertSame('restore', $this->calls[0][0]);
        $this->assertStringEndsWith('_restore_test', $this->calls[0][1]);
        $this->assertTrue($this->calls[0][2], 'The dump itself was loaded.');
        $this->assertSame(['drop', $this->calls[0][1]], $this->calls[1], 'The scratch copy is always removed.');
    }

    public function test_the_scratch_database_is_removed_even_when_the_restore_fails(): void
    {
        $this->failRestore = true;

        try {
            app(BackupService::class)->restoreTest();
            $this->fail('A failed restore must be reported.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('syntax error', $e->getMessage());
        }

        $this->assertSame('drop', end($this->calls)[0]);
    }

    public function test_a_table_that_did_not_come_back_is_reported(): void
    {
        $this->restoredCounts = array_fill_keys(self::CORE, 3); // invoices is in the dump but not in the restored copy

        $result = app(BackupService::class)->restoreTest();

        $this->assertFalse($result['ok']);
        $this->assertContains('Table invoices was in the backup but did not come back.', $result['problems']);
    }

    public function test_a_core_table_that_came_back_empty_while_the_live_one_has_rows_fails(): void
    {
        $this->restoredCounts = array_fill_keys([...self::CORE, 'invoices'], 3);
        $this->restoredCounts['medicines'] = 0;
        $this->liveCounts['medicines'] = 4812;

        $result = app(BackupService::class)->restoreTest();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('medicines came back empty', implode(' ', $result['problems']));
    }

    public function test_an_empty_core_table_is_fine_when_the_live_one_is_empty_too(): void
    {
        $this->restoredCounts = array_fill_keys([...self::CORE, 'invoices'], 0);
        $this->liveCounts = array_fill_keys(self::CORE, 0);

        $this->assertTrue(app(BackupService::class)->restoreTest()['ok']);
    }

    public function test_with_no_backup_there_is_nothing_to_restore(): void
    {
        Storage::fake('backup-test');

        $this->expectExceptionMessage('No backups found');
        app(BackupService::class)->restoreTest();
    }

    public function test_the_command_succeeds_quietly_and_fails_loudly(): void
    {
        $this->restoredCounts['invoices'] = 1;
        $this->artisan('backup:restore-test')->expectsOutputToContain('OK:')->assertExitCode(0);
        $this->assertDatabaseCount('notices', 0);

        $owner = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->restoredCounts = array_fill_keys(self::CORE, 3);
        $this->artisan('backup:restore-test')->expectsOutputToContain('FAILED')->assertExitCode(1);

        $this->assertDatabaseHas('notices', ['user_id' => $owner->id, 'kind' => 'backup_restore_failed']);
    }

    public function test_a_restore_that_cannot_run_also_tells_the_owner(): void
    {
        $owner = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->failRestore = true;

        $this->artisan('backup:restore-test')->assertExitCode(1);
        $this->assertDatabaseHas('notices', ['user_id' => $owner->id, 'kind' => 'backup_restore_failed']);
    }

    public function test_the_result_is_kept_and_shown_on_the_owner_dashboard(): void
    {
        $owner = User::factory()->create(['account_type' => AccountType::Admin]);
        $props = fn () => $this->actingAs($owner)->get('/dashboard')->viewData('page')['props'];
        $titles = fn () => collect($this->actingAs($owner)->get('/dashboard')->viewData('page')['props']['alerts'])->pluck('title');

        $this->assertNull(app(BackupService::class)->lastRestoreTest());

        $this->restoredCounts['invoices'] = 1;
        $this->artisan('backup:restore-test')->assertExitCode(0);
        $kept = app(BackupService::class)->lastRestoreTest();
        $this->assertTrue($kept['ok']);
        $this->assertFalse($titles()->contains('The last backup did not restore properly'));

        $this->restoredCounts = array_fill_keys(self::CORE, 3);
        $this->artisan('backup:restore-test')->assertExitCode(1);
        $this->assertFalse(app(BackupService::class)->lastRestoreTest()['ok']);
        $this->assertTrue($titles()->contains('The last backup did not restore properly'));

        $this->travel(41)->days();
        $this->assertTrue($titles()->contains('The last backup did not restore properly'), 'a failed test stays until a good one runs');
        $this->restoredCounts['invoices'] = 1;
        $this->artisan('backup:restore-test')->assertExitCode(0);
        $this->travel(41)->days();
        $this->assertTrue($titles()->contains('The monthly backup restore test has not run for over 40 days'));
    }

    public function test_a_restore_that_cannot_run_is_kept_as_failed(): void
    {
        $this->failRestore = true;
        $this->artisan('backup:restore-test')->assertExitCode(1);

        $kept = app(BackupService::class)->lastRestoreTest();
        $this->assertFalse($kept['ok']);
        $this->assertStringContainsString('syntax error', $kept['problem']);
    }

    public function test_the_mysql_restorer_will_only_touch_a_scratch_database_never_the_live_one(): void
    {
        $restorer = new MysqlRestorer;
        $live = (string) config('database.connections.'.config('database.default').'.database');

        foreach (['', 'ecommerce', 'mysql', $live, 'a b_restore_test', 'x`; DROP DATABASE y; -- _restore_test'] as $bad) {
            try {
                $restorer->drop($bad);
                $this->fail("Dropping '{$bad}' must be refused.");
            } catch (RuntimeException $e) {
                $this->assertSame('That is not a scratch database for a restore test.', $e->getMessage());
            }
        }
    }
}
