<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Loads a dump into a scratch MySQL database with the mysql client. The password never appears on the command line. */
class MysqlRestorer implements DatabaseRestorer
{
    public function restoreInto(string $database, string $sqlPath): void
    {
        $this->guard($database);
        $config = $this->config();

        // Start clean, so a left-over from an earlier test can never be mistaken for this one.
        DB::statement('DROP DATABASE IF EXISTS `'.$database.'`');
        DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $process = Process::fromShellCommandline(
            escapeshellarg((string) config('backup.mysql_path'))
            .' --host='.escapeshellarg((string) $config['host'])
            .' --port='.escapeshellarg((string) $config['port'])
            .' --user='.escapeshellarg((string) $config['username'])
            .' --default-character-set=utf8mb4 '.escapeshellarg($database)
            .' < '.escapeshellarg($sqlPath),
            null,
            ['MYSQL_PWD' => (string) ($config['password'] ?? '')],
        );
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The restore failed: '.trim($process->getErrorOutput()));
        }
    }

    public function tableCounts(string $database): array
    {
        $this->guard($database);
        $counts = [];

        foreach (DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$database, 'BASE TABLE']) as $row) {
            $counts[(string) $row->t] = (int) DB::selectOne('SELECT COUNT(*) AS n FROM `'.$database.'`.`'.str_replace('`', '', (string) $row->t).'`')->n;
        }

        return $counts;
    }

    public function liveCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    public function drop(string $database): void
    {
        $this->guard($database);
        DB::statement('DROP DATABASE IF EXISTS `'.$database.'`');
    }

    /** Only a database made for this purpose may be created or dropped here, and never the one the application runs on. */
    private function guard(string $database): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+_restore_test$/', $database) || $database === $this->config()['database']) {
            throw new RuntimeException('That is not a scratch database for a restore test.');
        }
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return config('database.connections.'.config('database.default'));
    }
}
