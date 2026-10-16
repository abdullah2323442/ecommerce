<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 20: in the wholesale pharmacy market the courier charge can be shared 50-50 or be a fixed amount, by weight and by carton; set when sending. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('courier_terms', 8)->nullable();
            $t->decimal('courier_cost', 12, 2)->nullable();
            $t->unsignedSmallInteger('cartons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['courier_terms', 'courier_cost', 'cartons']));
    }
};
