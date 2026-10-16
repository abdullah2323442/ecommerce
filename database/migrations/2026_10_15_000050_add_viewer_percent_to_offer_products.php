<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 9: an offer product can have its own rate for each group that sees it (public / paikari / wholesale). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_products', fn (Blueprint $t) => $t->json('viewer_percent')->nullable()->after('step_percent'));
    }

    public function down(): void
    {
        Schema::table('offer_products', fn (Blueprint $t) => $t->dropColumn('viewer_percent'));
    }
};
