<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 9.1: an offer product is the same stock with an offer tag. Only the lot bought at the higher discount goes
 * on offer, in boxes, with a minimum and an extra % for larger orders. The stock is never a separate pile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->decimal('percent', 5, 2);                      // discount off the box MRP
            $table->decimal('lot_percent', 6, 3);                  // only lots bought at this discount or better are on offer
            $table->unsignedSmallInteger('min_boxes')->default(1);
            $table->decimal('step_percent', 5, 2)->default(0);     // extra % for every box above the minimum
            $table->json('visible_to');                            // ["paikari", "public", "wholesale"]
            $table->string('stock_mode', 10)->default('in_stock'); // in_stock | exact
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('delivery_charge', 10, 2)->nullable();  // the fixed minimum delivery charge for this product
            $table->date('ends_on');
            $table->boolean('is_on')->default(true);
            $table->timestamp('soldout_at')->nullable();           // when the offer stock ran out (grey for a while, then hidden)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['medicine_id', 'is_on']);
        });

        Schema::table('invoice_lines', fn (Blueprint $t) => $t->foreignId('offer_product_id')->nullable()->after('from_invoice_id')->constrained('offer_products')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('offer_product_id'));
        Schema::dropIfExists('offer_products');
    }
};
