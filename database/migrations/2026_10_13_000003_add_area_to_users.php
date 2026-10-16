<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 4.3.5: two shops with the same name are told apart by area and phone.
        Schema::table('users', fn (Blueprint $t) => $t->string('area', 80)->nullable()->after('phone')->index());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('area'));
    }
};
