<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 15: where a sector allows pre-order, a sold-out product (or only its sold-out colour) can still be ordered; it ships when the seller has it again. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_orders', fn (Blueprint $t) => $t->boolean('preorder')->default(false));
    }

    public function down(): void
    {
        Schema::table('market_orders', fn (Blueprint $t) => $t->dropColumn('preorder'));
    }
};
