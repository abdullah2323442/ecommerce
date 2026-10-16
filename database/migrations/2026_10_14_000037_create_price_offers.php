<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 13: "Why didn't you buy", and a price the buyer proposes. The reason is kept for the seller; the price can become an order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 20);                                         // price | cheaper | not_wanted | delivery | not_now
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('offered_price', 12, 2)->nullable();                 // what the buyer would pay for each, with our commission in it
            $table->decimal('counter_price', 12, 2)->nullable();                 // the seller's answer, for each
            $table->decimal('commission_each', 12, 2)->default(0);               // ours, fixed when the offer is made
            // noted = only a reason | waiting = for the seller | accepted | countered | declined | expired | ordered | withdrawn
            $table->string('status', 12)->default('noted');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('market_order_id')->nullable()->constrained('market_orders')->nullOnDelete();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index(['buyer_id', 'status']);
            $table->index(['listing_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_offers');
    }
};
