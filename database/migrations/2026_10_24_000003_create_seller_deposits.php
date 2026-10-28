<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 14, security deposit: a seller may be asked to keep money with us against returns and unpaid commission. The option exists but is off until trust is built.
 * What is held is the sum of the entries; a seller's own required amount (if the owner sets one) overrides the usual amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $t->decimal('required', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('deposit_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $t->decimal('amount', 12, 2);     // plus when we take it in, minus when it goes back or is deducted
            $t->string('kind', 10);           // received | returned | deducted
            $t->string('reason', 200)->nullable();
            $t->foreignId('by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['seller_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_entries');
        Schema::dropIfExists('seller_deposits');
    }
};
