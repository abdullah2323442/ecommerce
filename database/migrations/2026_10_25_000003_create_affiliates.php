<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Someone outside the business who sends buyers to the site and earns a share of what those buyers spend (spec ch 27, affiliate).
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();   // when the affiliate also has an account, they can see their own page
            $table->string('name', 120);
            $table->string('phone', 20)->nullable();
            $table->string('code', 24)->unique();
            $table->decimal('percent', 5, 2);                                                    // of the goods a buyer pays for, delivery not counted
            $table->boolean('active')->default(true);
            $table->unsignedInteger('clicks')->default(0);
            $table->string('note', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 12);
            $table->string('note', 200)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
        });

        // One row for each order that earned something, once. A return after a payout is a negative row that the next payout takes off.
        Schema::create('affiliate_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                                                          // earn | takeback
            $table->string('source_type', 10);                                                   // invoice | market
            $table->unsignedBigInteger('source_id');
            $table->decimal('base', 12, 2);
            $table->decimal('percent', 5, 2);
            $table->decimal('amount', 12, 2);                                                    // negative for a take-back
            $table->string('status', 8)->default('owed');                                        // owed | paid | void
            $table->foreignId('payout_id')->nullable()->constrained('affiliate_payouts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'kind']);
            $table->index(['affiliate_id', 'status']);
        });

        Schema::table('invoices', fn (Blueprint $table) => $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->nullOnDelete());
        Schema::table('market_orders', fn (Blueprint $table) => $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('market_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_id');
        });
        Schema::dropIfExists('affiliate_earnings');
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliates');
    }
};
