<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The month-end statement to a seller: "sales were X, our commission is Y" (spec ch 14). They answer Paid or Problem; no answer, no selling.
        Schema::create('market_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('month', 7);                 // 2026-09
            $table->unsignedInteger('orders');
            $table->decimal('sales', 12, 2);
            $table->decimal('commission', 12, 2);
            $table->string('status', 10)->default('sent');   // sent | paid | problem | verified | suspended
            $table->string('seller_note', 300)->nullable();
            $table->string('our_note', 300)->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seller_id', 'month']);
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            // Selling is switched off (not the whole account) when a statement is left unanswered.
            $table->timestamp('seller_suspended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('seller_suspended_at'));
        Schema::dropIfExists('market_statements');
    }
};
