<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 03 and 15: a seller who runs two businesses (wood and clothing) may give each its own name. Kept as sector slug => name. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->json('business_names')->nullable()->after('logo_path'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('business_names'));
    }
};
