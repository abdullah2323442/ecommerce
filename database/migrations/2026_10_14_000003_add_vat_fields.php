<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 25 (dev note): keep the VAT fields from the start, even while VAT is off. A rate can be set per company or per
 * medicine, and every memo line records the rate and the VAT inside its price once VAT is switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->decimal('vat_percent', 5, 2)->nullable());
        Schema::table('medicines', fn (Blueprint $t) => $t->decimal('vat_percent', 5, 2)->nullable());
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->decimal('vat_percent', 5, 2)->default(0);
            $t->decimal('vat_amount', 12, 2)->default(0);     // the VAT inside the line total, as the printed price already includes it
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn(['vat_percent', 'vat_amount']));
        Schema::table('medicines', fn (Blueprint $t) => $t->dropColumn('vat_percent'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('vat_percent'));
    }
};
