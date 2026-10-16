<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penalty steps (spec ch 14): warning, listing removed, suspended, payouts withheld. The owner chooses the step; nothing here is automatic.
        Schema::create('seller_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('step', 16);                     // warning | listing_removed | suspended | withheld
            $table->string('reason', 300);
            $table->foreignId('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'lifted_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('penalty_suspended_at')->nullable();   // selling off as a penalty (a statement stop is seller_suspended_at)
            $table->timestamp('payout_withheld_at')->nullable();
            $table->timestamp('seller_trusted_at')->nullable();      // the owner trusts this seller before the clean orders are there
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['penalty_suspended_at', 'payout_withheld_at', 'seller_trusted_at']));
        Schema::dropIfExists('seller_penalties');
    }
};
