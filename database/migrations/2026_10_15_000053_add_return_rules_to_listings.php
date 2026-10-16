<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 15: the return flow is the same everywhere, but the days and conditions are the seller's own. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $t) {
            $t->unsignedTinyInteger('return_days')->nullable();
            $t->string('return_note', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['return_days', 'return_note']));
    }
};
