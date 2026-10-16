<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Nightly database backup (spec ch 28 note 7): compressed dump on a configurable disk,
 * at least 30 days kept, integrity-checked, and the disk must be OFF the web server in production.
 */
class BackupService
{
    private const PREFIX = 'db-';

    public function __construct(private DatabaseDumper $dumper) {}

    /** Create today's backup and return its file name on the backup disk. */
    public function run(): string
    {
        $name = self::PREFIX.now()->format('Y-m-d_His').'.sql.gz';
        $tmp = tempnam(sys_get_temp_dir(), 'dump');
        $gz = $tmp.'.gz';

        try {
            $this->dumper->dumpTo($tmp);
            $this->gzip($tmp, $gz);

            $stream = fopen($gz, 'rb');
            Storage::disk($this->disk())->put(trim(config('backup.directory'), '/').'/'.$name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            @unlink($tmp);
            @unlink($gz);
        }

        return $name;
    }

    /** Delete backups older than the retention window. Returns the deleted file names. */
    public function prune(): array
    {
        $cutoff = now()->subDays((int) config('backup.retention_days'))->getTimestamp();
        $deleted = [];

        foreach ($this->list() as $file) {
            if (Storage::disk($this->disk())->lastModified($file) < $cutoff) {
                Storage::disk($this->disk())->delete($file);
                $deleted[] = basename($file);
            }
        }

        return $deleted;
    }

    /** @return list<string> backup file paths, newest first */
    public function list(): array
    {
        $files = Storage::disk($this->disk())->files(trim(config('backup.directory'), '/'));
        $files = array_values(array_filter($files, fn ($f) => str_starts_with(basename($f), self::PREFIX) && str_ends_with($f, '.sql.gz')));
        rsort($files);

        return $files;
    }

    /**
     * Integrity check of the newest backup: it must decompress and contain the core tables.
     * This is not a full restore test; do that by hand into a scratch database every month.
     *
     * @return array{file: string, bytes: int}
     */
    public function verifyLatest(): array
    {
        $file = $this->list()[0] ?? throw new RuntimeException('No backups found.');
        $content = $this->read($file);

        if ($content === '' || ! str_contains($content, 'CREATE TABLE')) {
            throw new RuntimeException("Backup {$file} is empty or has no tables.");
        }

        foreach (config('backup.required_tables') as $table) {
            if (! str_contains($content, "CREATE TABLE `{$table}`")) {
                throw new RuntimeException("Backup {$file} is missing table {$table}.");
            }
        }

        return ['file' => basename($file), 'bytes' => strlen($content)];
    }

    /** When the newest backup was written, or null if there is none. */
    public function latestAt(): ?CarbonImmutable
    {
        $latest = $this->list()[0] ?? null;

        return $latest === null
            ? null
            : CarbonImmutable::createFromTimestamp(Storage::disk($this->disk())->lastModified($latest));
    }

    /** Warn if the newest backup is older than a day and a half. */
    public function isStale(): bool
    {
        $latest = $this->list()[0] ?? null;

        return $latest === null
            || Storage::disk($this->disk())->lastModified($latest) < now()->subHours(36)->getTimestamp();
    }

    private function read(string $file): string
    {
        $gz = gzopen('data://application/octet-stream;base64,'.base64_encode((string) Storage::disk($this->disk())->get($file)), 'rb');
        if ($gz === false) {
            return '';
        }

        $out = '';
        while (! gzeof($gz)) {
            $out .= gzread($gz, 1 << 20);
        }
        gzclose($gz);

        return $out;
    }

    private function gzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb9');
        while (! feof($in)) {
            gzwrite($out, fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
    }

    private function disk(): string
    {
        return config('backup.disk');
    }
}
