<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Models\User;
use App\Enums\AccountType;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupRestoreTest extends Command
{
    protected $signature = 'backup:restore-test';

    protected $description = 'Load the newest backup into a scratch database, check it came back whole, and throw the scratch copy away';

    public function handle(BackupService $backups): int
    {
        try {
            $result = $backups->restoreTest();
        } catch (Throwable $e) {
            $this->error('The restore test could not run: '.$e->getMessage());
            report($e);
            $backups->rememberRestoreTest(false, null, 0, $e->getMessage());
            $this->tellOwner('The monthly backup restore test could not run: '.$e->getMessage());

            return self::FAILURE;
        }

        $backups->rememberRestoreTest($result['ok'], $result['file'], $result['tables'], implode(' ', $result['problems']));

        foreach ($result['core'] as $table => $n) {
            $this->line(sprintf('  %-16s restored %d, live %d', $table, $n['restored'], $n['live']));
        }

        if (! $result['ok']) {
            $this->error('Restore test FAILED for '.$result['file'].': '.implode(' ', $result['problems']));
            $this->tellOwner('The newest backup did not restore properly: '.implode(' ', $result['problems']));

            return self::FAILURE;
        }

        $this->info("OK: {$result['file']} restored {$result['tables']} tables into a scratch database, which has been removed.");

        return self::SUCCESS;
    }

    /** A failed restore test is something the owner must hear about, not a line in a log. */
    private function tellOwner(string $body): void
    {
        foreach (User::where('account_type', AccountType::Admin)->get() as $owner) {
            Notice::create(['user_id' => $owner->id, 'kind' => 'backup_restore_failed', 'body' => mb_substr($body, 0, 250)]);
        }
    }
}
