<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 25, 26: notifications sorted by importance, urgent on top, so a pile of messages never hides the one that matters. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notices', 'importance')) {
            Schema::table('notices', function (Blueprint $table) {
                $table->string('importance', 8)->default('normal')->after('kind');   // urgent | normal | low
            });
        }
    }

    public function down(): void
    {
        Schema::table('notices', fn (Blueprint $table) => $table->dropColumn('importance'));
    }
};
