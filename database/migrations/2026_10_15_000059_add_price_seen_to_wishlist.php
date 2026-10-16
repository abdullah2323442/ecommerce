<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 13 (Phase 2): a price-drop alert on a saved product. The price the buyer last saw is kept, so only a real fall is told. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlist_items', fn (Blueprint $t) => $t->decimal('price_seen', 12, 2)->nullable());
    }

    public function down(): void
    {
        Schema::table('wishlist_items', fn (Blueprint $t) => $t->dropColumn('price_seen'));
    }
};
