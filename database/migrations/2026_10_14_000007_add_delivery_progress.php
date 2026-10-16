<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 20: an order is sent (maybe with an extra delivery charge added at that moment, "the delivery charge draft"), the
 * buyer sees "on the way" and confirms it arrived, or says it did not. With no answer it confirms itself after some days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
            $table->string('tracking', 80)->nullable();
            $table->decimal('delivery_extra', 10, 2)->default(0);          // added to the delivery charge when it was sent
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivered_by', 8)->nullable();                // buyer | auto
            $table->timestamp('not_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['sent_at', 'tracking', 'delivery_extra', 'delivered_at', 'delivered_by', 'not_received_at']));
    }
};
