<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 13: one cart, one checkout, split by seller. The orders made from one checkout share a private group link, so the buyer sees them as packages. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_orders', function (Blueprint $t) {
            $t->string('group_token', 40)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', fn (Blueprint $t) => $t->dropColumn('group_token'));
    }
};
