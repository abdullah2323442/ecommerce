<?php

namespace App\Services\Backup;

/** What a restore test needs from the database: a scratch copy to load a dump into, a way to count it, and a way to throw it away. */
interface DatabaseRestorer
{
    /** Make an empty scratch database called $database and load the plain SQL file $sqlPath into it. */
    public function restoreInto(string $database, string $sqlPath): void;

    /** @return array<string, int> every table in $database with how many rows it holds */
    public function tableCounts(string $database): array;

    /** How many rows the live application database holds in each of these tables. @param list<string> $tables @return array<string, int> */
    public function liveCounts(array $tables): array;

    /** Remove the scratch database. Never touches anything but a database made for a restore test. */
    public function drop(string $database): void;
}
