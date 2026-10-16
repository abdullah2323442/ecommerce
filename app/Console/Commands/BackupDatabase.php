<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'backup:run {--verify : Verify the new backup right after creating it}';

    protected $description = 'Dump the database to the backup disk and prune copies older than the retention window';

    public function handle(BackupService $backups): int
    {
        try {
            $name = $backups->run();
            $this->info("Backup created: {$name}");

            if ($this->option('verify')) {
                $check = $backups->verifyLatest();
                $this->info("Verified {$check['file']} ({$check['bytes']} bytes of SQL).");
            }

            foreach ($backups->prune() as $old) {
                $this->line("Pruned old backup: {$old}");
            }
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
