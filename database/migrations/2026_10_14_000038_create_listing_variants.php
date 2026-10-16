<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 15: one product, many colours and sizes in one grid; sold out is per colour; own price and photo per colour or size; wood sizes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('color', 40)->default('');                     // '' means no colour
            $table->string('size', 40)->default('');                      // '' means no size
            $table->decimal('stock', 12, 2)->nullable();                  // null: the seller does not count it
            $table->decimal('public_price', 12, 2)->nullable();           // null: the product's own price
            $table->decimal('wholesale_price', 12, 2)->nullable();
            $table->string('photo_path')->nullable();                     // choosing a colour changes the photo
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['listing_id', 'color', 'size']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->decimal('item_height', 8, 2)->nullable()->after('min_qty');
            $table->decimal('item_length', 8, 2)->nullable()->after('item_height');
            $table->decimal('item_width', 8, 2)->nullable()->after('item_length');
            $table->string('item_size_unit', 6)->default('inch')->after('item_width');
        });

        Schema::table('market_orders', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('listing_id')->constrained('listing_variants')->nullOnDelete();
            $table->string('variant_label', 90)->nullable()->after('variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('variant_id');
            $t->dropColumn('variant_label');
        });
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['item_height', 'item_length', 'item_width', 'item_size_unit']));
        Schema::dropIfExists('listing_variants');
    }
};
