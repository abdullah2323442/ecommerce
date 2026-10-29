<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 09: the owner is warned once, before an offer's stock runs out. This remembers that the warning went, until new stock comes in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_products', fn (Blueprint $t) => $t->dateTime('low_alerted_at')->nullable()->after('soldout_at'));
    }

    public function down(): void
    {
        Schema::table('offer_products', fn (Blueprint $t) => $t->dropColumn('low_alerted_at'));
    }
};
