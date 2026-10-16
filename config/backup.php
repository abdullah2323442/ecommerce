<?php

return [
    /*
     * Filesystem disk that receives backups. In production this MUST be a disk on a different
     * machine (S3-compatible bucket, SFTP, etc.), never the web server's own disk, so a server
     * failure cannot take the backups with it (spec ch 28 note 7). Define it in config/filesystems.php.
     */
    'disk' => env('BACKUP_DISK', 'local'),

    'directory' => env('BACKUP_DIRECTORY', 'backups'),

    // Spec: at least 30 days of copies.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),

    // Tables that must appear in a backup for it to count as valid.
    'required_tables' => ['users', 'medicines', 'companies', 'activity_logs'],
];
