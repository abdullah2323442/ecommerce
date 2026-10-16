<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sectors are made by the owner, not coded (spec ch 15): one system, any number of kinds of business.
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();      // matches the category a trade account is given (config/wholesale.php)
            $table->string('name', 80);
            $table->string('name_bn', 80)->nullable();
            $table->string('unit', 20)->default('piece');   // piece, foot, yard, kg, sack, litre...
            $table->boolean('allow_fraction')->default(false);   // 2.5 yards of cloth
            $table->decimal('premium_over', 12, 2)->nullable(); // above this price a product goes to the Premium stall
            $table->boolean('preorder')->default(false);
            $table->boolean('chat')->default(false);
            $table->string('return_rule', 300)->nullable();      // shown on the product page; the seller handles the return
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // What a seller offers (spec ch 14). The seller sets the price; our commission is added on top; we approve before it shows.
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('photo_path')->nullable();
            $table->decimal('public_price', 12, 2)->nullable();      // null: not sold to the public
            $table->decimal('wholesale_price', 12, 2)->nullable();   // null: not sold wholesale
            $table->decimal('min_qty', 12, 2)->default(1);           // the wholesale minimum
            $table->decimal('delivery_charge', 10, 2)->default(0);   // the seller decides it
            $table->decimal('stock', 12, 2)->nullable();             // basic stock; null: not counted
            $table->string('status', 10)->default('pending');        // pending | published | rejected | paused
            $table->string('commission_kind', 8)->nullable();        // percent | taka: set by us when we approve
            $table->decimal('commission_value', 10, 2)->nullable();
            $table->string('commission_request', 300)->nullable();   // the seller asking for less
            $table->string('review_note', 300)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('flag', 120)->nullable();                 // a word from the prohibited list, for the reviewer
            $table->timestamps();

            $table->index(['status', 'sector_id']);
            $table->index('seller_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // Every product shows our name unless the owner turns the seller's own name on (spec ch 05).
            $table->boolean('show_shop_name')->default(false);
            $table->boolean('seller_paused')->default(false);   // vacation: no new orders
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['show_shop_name', 'seller_paused']));
        Schema::dropIfExists('listings');
        Schema::dropIfExists('sectors');
    }
};
