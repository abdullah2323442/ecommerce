<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 07 step 9-B: the free-delivery amount is set for each paikari shop. Empty means the site-wide amount applies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->decimal('free_delivery_over', 12, 2)->nullable()->after('show_monthly_total'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('free_delivery_over'));
    }
};
