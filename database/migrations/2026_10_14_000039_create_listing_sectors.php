<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 15: one product in several categories with one stock; a seller's own purchase price and low-stock alert. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_sectors', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
            $table->primary(['listing_id', 'sector_id']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->nullable()->after('wholesale_price');     // the seller's own cost for one: private, optional
            $table->decimal('low_stock_at', 12, 2)->nullable()->after('stock');              // tell the seller when it falls to this
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['cost_price', 'low_stock_at']));
        Schema::dropIfExists('listing_sectors');
    }
};
