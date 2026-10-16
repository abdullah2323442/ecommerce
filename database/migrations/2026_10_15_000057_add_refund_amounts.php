<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 12: money when goods are not taken — a delivery charge cancelled before sending gives back half, a product advance gives back half. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->decimal('delivery_refund', 12, 2)->default(0));
        Schema::table('preorders', fn (Blueprint $t) => $t->decimal('advance_refunded', 12, 2)->default(0));
    }

    public function down(): void
    {
        Schema::table('preorders', fn (Blueprint $t) => $t->dropColumn('advance_refunded'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('delivery_refund'));
    }
};
