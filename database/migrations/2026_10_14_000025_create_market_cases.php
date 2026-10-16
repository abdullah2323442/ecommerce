<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A buyer's complaint or return on a market order (spec ch 14). The seller sees it and solves it; we get a copy and decide when it is not solved.
        Schema::create('market_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_order_id')->constrained('market_orders')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10);                          // complaint | return
            $table->string('reason', 300);
            $table->string('status', 10)->default('open');       // open | solved | returned | escalated | closed
            $table->string('seller_note', 300)->nullable();
            $table->string('our_note', 300)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index('status');
        });

        Schema::table('market_orders', function (Blueprint $table) {
            // A returned order is no longer a complete sale: the goods went straight back to the seller, and no commission is due.
            $table->timestamp('returned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', fn (Blueprint $table) => $table->dropColumn('returned_at'));
        Schema::dropIfExists('market_cases');
    }
};
