<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupVerify extends Command
{
    protected $signature = 'backup:verify';

    protected $description = 'Check that the newest backup decompresses and contains the core tables, and that it is fresh';

    public function handle(BackupService $backups): int
    {
        try {
            $check = $backups->verifyLatest();
        } catch (Throwable $e) {
            $this->error('Backup verification failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info("OK: {$check['file']} ({$check['bytes']} bytes of SQL).");

        if ($backups->isStale()) {
            $this->warn('The newest backup is older than 36 hours. Check the nightly schedule.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
