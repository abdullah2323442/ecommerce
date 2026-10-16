<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 10: the MPO sector. An MPO is a company representative whose account only the owner opens. They pick, from the
 * companies they are given, the products they work with, and submit them; the owner approves, sets the markets and the price in
 * each, and buyers see only "Anonymous N (store name)". Both sides stay masked; only the owner sees everyone.
 * One MPO has many companies and many chosen products, built as real many-to-many tables from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('mpo_number')->nullable()->unique();   // the number in "Anonymous 3"
            $table->string('mpo_note', 500)->nullable();                   // the owner's own comments
        });

        Schema::create('mpo_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'company_id']);
        });

        Schema::create('mpo_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'medicine_id']);
        });

        Schema::create('mpo_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();       // the MPO
            $table->foreignId('medicine_id')->constrained();
            $table->string('status', 10)->default('submitted');                    // submitted | approved | rejected | closed
            $table->string('stock_status', 10)->default('in_stock');               // in_stock | pre_order
            $table->unsignedTinyInteger('arrive_days')->nullable();               // when it is a pre-order
            $table->unsignedInteger('quantity')->nullable();                       // boxes the MPO says there are
            $table->unsignedInteger('bonus_buy')->nullable();                      // 10 in "10+2"
            $table->unsignedInteger('bonus_free')->nullable();                     // 2 in "10+2"
            $table->boolean('show_bonus')->default(false);                         // decided by the owner
            $table->boolean('show_quantity')->default(false);                      // the exact number, or only "In stock"
            $table->json('markets')->nullable();                                   // wholesale, paikari, public, decided by the owner
            $table->decimal('price_wholesale', 12, 2)->nullable();                 // per box, in each market
            $table->decimal('price_paikari', 12, 2)->nullable();
            $table->decimal('price_public', 12, 2)->nullable();
            $table->string('review_note', 200)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'medicine_id']);
        });

        // A pre-order that is really an order from an MPO listing: the goods pass through the owner's hands (spec ch 10).
        Schema::table('preorders', function (Blueprint $table) {
            $table->foreignId('mpo_listing_id')->nullable()->constrained('mpo_listings')->nullOnDelete();
            $table->decimal('box_price', 12, 2)->nullable();                       // the price agreed for the buyer's market
            $table->unsignedInteger('bonus_free')->nullable();                     // free boxes that come with this order
        });
    }

    public function down(): void
    {
        Schema::table('preorders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mpo_listing_id');
            $table->dropColumn(['box_price', 'bonus_free']);
        });
        Schema::dropIfExists('mpo_listings');
        Schema::dropIfExists('mpo_products');
        Schema::dropIfExists('mpo_companies');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mpo_number', 'mpo_note']));
    }
};
