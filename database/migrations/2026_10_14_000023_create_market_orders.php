<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An order for a seller's listing (spec ch 14). The seller packs, ships and is paid by the buyer directly; we only keep the record
        // and, once the sale is complete, our commission. Money does not pass through us.
        Schema::create('market_orders', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 40)->unique();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);                 // what it was called when ordered
            $table->string('unit', 20);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);         // what the buyer pays for one: the seller's price and our commission
            $table->decimal('seller_price', 12, 2);       // what the seller gets for one
            $table->decimal('commission', 12, 2);         // ours, for the whole order
            $table->decimal('delivery_charge', 10, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('audience', 10);               // public | wholesale: which price it was bought at
            $table->json('ship_to');
            $table->string('note', 300)->nullable();
            $table->string('status', 12)->default('placed');   // placed | accepted | shipped | delivered | cancelled | not_received
            $table->string('cancel_reason', 200)->nullable();
            $table->string('cancelled_by', 10)->nullable();    // buyer | seller
            $table->string('tracking', 120)->nullable();
            $table->timestamp('seller_seen_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('not_received_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index('buyer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_orders');
    }
};
