<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 2: one invoice model for the counter and for online orders (spec 4.3.5, 4.4.2, 4.5). A draft is the
 * `open_invoice`: it stays open while goods are added, every line saved to the server at once (DRF-9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();      // the memo number, given on finalising
            $table->string('kind', 12);                               // retail | paikari | wholesale
            $table->string('channel', 10)->default('counter');        // counter | online
            $table->string('status', 12)->default('draft');           // draft | final | cancelled
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 80)->nullable();                  // "Customer 1" for retail drafts
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('invoice_percent', 5, 2)->nullable();     // paikari/wholesale: one % for the whole invoice
            $table->decimal('end_discount_percent', 5, 2)->default(0); // retail: one discount at the bottom
            $table->string('delivery_mode', 12)->nullable();          // collect | send | delivery
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('payment_method', 20)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('note', 300)->nullable();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('finalised_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
            $table->index('customer_id');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->foreignId('from_invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // set when drafts are merged
            $table->string('unit', 8);                                // piece | strip | box
            $table->decimal('quantity', 12, 2);
            $table->decimal('stock_units', 14, 4);                    // the same quantity in the stock unit (boxes, or pieces for loose items)
            $table->decimal('unit_mrp', 14, 2);
            $table->decimal('unit_price', 14, 2);                     // after the rate that applied, per chosen unit
            $table->string('rate_layer', 20)->default('mrp');         // mrp | special | invoice | customer_manual | medicine | company | tier
            $table->decimal('percent_off', 6, 3)->default(0);
            $table->decimal('special_percent', 5, 2)->nullable();     // typed for this line at the counter
            $table->boolean('is_outside')->default(false);            // "bring from outside": priced, but stock is not touched (DRF-10)
            $table->timestamp('removed_at')->nullable();              // removed lines stay for the history
            $table->timestamps();

            $table->index(['invoice_id', 'removed_at']);
        });

        // Which stock movements a line caused, so removing the line can put exactly that stock back.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('invoice_line_id')->nullable()->after('stock_lot_id')->constrained('invoice_lines')->nullOnDelete();
            $table->boolean('reversed')->default(false)->after('is_loss');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_line_id');
            $table->dropColumn('reversed');
        });
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
