<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 29C: two names for one thing ("tablet", "bori") — the other names are linked in search. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->string('alt_names', 200)->nullable());
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn('alt_names'));
    }
};
