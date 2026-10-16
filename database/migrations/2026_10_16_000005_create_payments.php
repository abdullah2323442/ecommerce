<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Online payment (spec ch 27): one record for every try to pay, whatever it pays for. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 40)->unique();                         // our transaction id; the gateway sends it back
            $table->string('payable_type', 40);                          // what it pays for: invoice | market_order …
            $table->unsignedBigInteger('payable_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);                              // sslcommerz
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BDT');
            // started | paid | failed | cancelled | refund_due | refunded
            $table->string('status', 12)->default('started');
            $table->string('val_id', 80)->nullable();                    // the gateway's own id, proven by asking them
            $table->string('bank_tran_id', 80)->nullable();
            $table->string('method', 60)->nullable();                    // how they paid, for the office (bKash, a card …)
            $table->unsignedTinyInteger('risk_level')->nullable();
            $table->boolean('live')->default(false);                     // real money, or the gateway's practice mode
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
