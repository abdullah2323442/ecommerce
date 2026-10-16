<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 20: the shop's own list of couriers with charges typed by hand (no courier integration), a weight on products, and a
 * mandatory delivery charge a product can carry. The buyer picks a courier at checkout when every product has a weight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->decimal('cod_percent', 5, 2)->default(0);       // what the courier takes for collecting the cash
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('courier_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->string('zone', 10);                              // own | district | outside
            $table->decimal('first_kg', 10, 2);                      // the charge for the first kilogram
            $table->decimal('extra_kg', 10, 2);                      // for each kilogram after that
            $table->unique(['courier_id', 'zone']);
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->unsignedInteger('weight_g')->nullable();         // grams per stock unit (a box, or a piece for loose items)
            $table->decimal('delivery_fixed_in', 10, 2)->nullable(); // a mandatory delivery charge inside the shop's district
            $table->decimal('delivery_fixed_out', 10, 2)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('courier_id'));
        Schema::table('medicines', fn (Blueprint $t) => $t->dropColumn(['weight_g', 'delivery_fixed_in', 'delivery_fixed_out']));
        Schema::dropIfExists('courier_rates');
        Schema::dropIfExists('couriers');
    }
};
