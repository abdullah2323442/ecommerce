<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec ch 13: "I was looking for this" works in every sector and can carry a note or a photo, so the owner sees where the want is and what it looks like. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $t) {
            $t->string('sector', 30)->nullable()->after('source');
            $t->string('photo_path', 200)->nullable()->after('sector');
        });

        // Every request so far came from the medicine search.
        DB::table('product_requests')->whereNull('sector')->update(['sector' => 'medicine']);
    }

    public function down(): void
    {
        Schema::table('product_requests', fn (Blueprint $t) => $t->dropColumn(['sector', 'photo_path']));
    }
};
