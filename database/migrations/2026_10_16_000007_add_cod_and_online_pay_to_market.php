<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec ch 13: cash on delivery is set per product. Everything already listed keeps it.
        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('cod_allowed')->default(true);
        });

        // How an order is paid: to the seller on delivery ("cod") or to us online ("online"). An online order waits for its payment; the seller is paid out once it is delivered.
        Schema::table('market_orders', function (Blueprint $table) {
            $table->string('pay_by', 10)->default('cod');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('payout_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', function (Blueprint $table) {
            $table->dropColumn(['pay_by', 'paid_at', 'payout_at']);
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('cod_allowed');
        });
    }
};
