<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 08 and 13: what people asked for and we did not have comes from two places, the website search and the counter. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', fn (Blueprint $t) => $t->string('source', 10)->default('online')->after('note'));
    }

    public function down(): void
    {
        Schema::table('product_requests', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
