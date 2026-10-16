<?php

namespace App\Services\Backup;

interface DatabaseDumper
{
    /** Write a full SQL dump of the application database to $path (plain, uncompressed SQL). */
    public function dumpTo(string $path): void;
}
