<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 20: a buyer collects from the seller's own shop, to save the delivery charge. Only for a seller whose name and place are shown. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('shop_address', 250)->nullable()->after('seller_paused');       // where buyers may collect
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('pickup')->default(false)->after('delivery_charge');           // the seller allows collecting this one
        });

        Schema::table('market_orders', function (Blueprint $table) {
            $table->boolean('pickup')->default(false)->after('delivery_charge');
            $table->timestamp('pickup_ready_at')->nullable()->after('accepted_at');        // the seller said it is ready
            $table->timestamp('pickup_reminded_at')->nullable()->after('pickup_ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', fn (Blueprint $t) => $t->dropColumn(['pickup', 'pickup_ready_at', 'pickup_reminded_at']));
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn('pickup'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('shop_address'));
    }
};
