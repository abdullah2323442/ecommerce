<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 24: the wallet and the Aqua Token. We give platform tokens to people; each seller has one token for their own products. */
return new class extends Migration
{
    public function up(): void
    {
        // A token we gave to one person: works in every sector, once.
        Schema::create('aqua_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('note', 160)->nullable();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            // Where it was spent. Cleared again when that order is cancelled, so the token comes back.
            $table->string('spent_on', 12)->nullable();                // invoice | market
            $table->unsignedBigInteger('spent_id')->nullable();
            $table->timestamp('spent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'spent_at']);
            $table->index(['spent_on', 'spent_id']);
        });

        // A seller's own token: one for all their buyers, only on their products, paid for by the seller.
        Schema::create('seller_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('min_order', 12, 2)->default(0);
            $table->unsignedSmallInteger('per_buyer')->default(1);     // how many times one buyer may use it
            $table->boolean('active')->default(true);
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seller_token_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_token_id')->constrained('seller_tokens')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('market_order_id')->constrained('market_orders')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('voided_at')->nullable();                // set when the order is cancelled
            $table->timestamps();

            $table->index(['seller_token_id', 'buyer_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('aqua_token_id')->nullable()->after('credit_applied')->constrained('aqua_tokens')->nullOnDelete(); // chosen at checkout
            $table->decimal('token_applied', 12, 2)->default(0)->after('aqua_token_id');                                         // taken off when finished
        });

        Schema::table('market_orders', function (Blueprint $table) {
            $table->decimal('token_amount', 12, 2)->default(0)->after('total');
            $table->string('token_kind', 10)->nullable()->after('token_amount');                       // platform | seller
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', fn (Blueprint $t) => $t->dropColumn(['token_amount', 'token_kind']));
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('aqua_token_id');
            $t->dropColumn('token_applied');
        });
        Schema::dropIfExists('seller_token_uses');
        Schema::dropIfExists('seller_tokens');
        Schema::dropIfExists('aqua_tokens');
    }
};
