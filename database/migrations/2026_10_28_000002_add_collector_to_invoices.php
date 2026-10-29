<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 07 step 9: a shop can send someone to collect its order. The person's name and phone are kept so the counter hands the goods to the right one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('collector_name', 80)->nullable()->after('delivery_mode');
            $t->string('collector_phone', 20)->nullable()->after('collector_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['collector_name', 'collector_phone']));
    }
};
