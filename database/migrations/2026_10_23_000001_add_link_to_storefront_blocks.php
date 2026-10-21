<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A section's own button can lead somewhere the admin chooses (a path on this site). */
    public function up(): void
    {
        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->string('link', 200)->nullable()->after('button_bn');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
};
