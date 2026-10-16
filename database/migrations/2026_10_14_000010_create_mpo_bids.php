<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 10: blind bidding. A bulk buyer can offer their own price on an MPO product as a discount percentage. Several can bid.
 * Each sees only their own bid; the MPO sees the bids without names; only the owner sees who bid. The MPO accepts one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpo_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mpo_listing_id')->constrained('mpo_listings')->cascadeOnDelete();
            $table->foreignId('bidder_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('boxes');
            $table->decimal('percent', 5, 2);                      // the discount off the printed price they ask for
            $table->decimal('box_price', 12, 2);                   // what that comes to per box
            $table->string('status', 10)->default('open');         // open | accepted | rejected | withdrawn
            $table->timestamps();

            $table->index(['mpo_listing_id', 'status']);
        });

        Schema::table('mpo_listings', function (Blueprint $table) {
            $table->timestamp('stale_told_at')->nullable();       // when the MPO was last asked to raise the percentage
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('mpo_failed')->default(0);    // accepted orders the MPO did not deliver in time
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('mpo_failed'));
        Schema::table('mpo_listings', fn (Blueprint $t) => $t->dropColumn('stale_told_at'));
        Schema::dropIfExists('mpo_bids');
    }
};
