<?php

use App\Models\Sector;
use App\Models\StorefrontBlock;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** The electronics shop keeps the front page it already had, now as sections the admin can change. */
    public function up(): void
    {
        $sector = Sector::where('slug', 'electronics')->first();

        if ($sector) {
            StorefrontBlock::seedDefaults($sector);
        }
    }

    public function down(): void {}
};
