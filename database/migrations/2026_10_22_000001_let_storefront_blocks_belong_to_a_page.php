<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A section belongs to a shop (sector_id) or to one of the stand-alone pages: pharmacy, food or game top-up (page). */
    public function up(): void
    {
        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->string('page', 16)->nullable()->after('sector_id');
            $table->index(['page', 'sort']);
        });

        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->dropForeign(['sector_id']);
        });

        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->unsignedBigInteger('sector_id')->nullable()->change();
            $table->foreign('sector_id')->references('id')->on('sectors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('storefront_blocks', function (Blueprint $table) {
            $table->dropIndex(['page', 'sort']);
            $table->dropColumn('page');
        });
    }
};
