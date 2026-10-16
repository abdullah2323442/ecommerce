<?php

namespace App\Services\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Dumps the default MySQL connection with mysqldump. The password never appears on the command line. */
class MysqlDumper implements DatabaseDumper
{
    public function dumpTo(string $path): void
    {
        $config = config('database.connections.'.config('database.default'));

        $process = new Process([
            config('backup.mysqldump_path'),
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
            '--routines',
            '--default-character-set=utf8mb4',
            '--result-file='.$path,
            $config['database'],
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);

        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($path);

            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }
    }
}
