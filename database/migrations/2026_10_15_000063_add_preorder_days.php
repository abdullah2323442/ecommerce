<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 21: whoever turns pre-order on for their own product names the time — 2, 3, 4 or 5 days — and the order goes to them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->unsignedTinyInteger('preorder_days')->nullable());
        Schema::table('market_orders', fn (Blueprint $t) => $t->unsignedTinyInteger('preorder_days')->nullable());
    }

    public function down(): void
    {
        Schema::table('market_orders', fn (Blueprint $t) => $t->dropColumn('preorder_days'));
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn('preorder_days'));
    }
};
