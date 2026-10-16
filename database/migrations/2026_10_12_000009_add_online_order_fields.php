<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online orders from shops (spec 4.4): the buyer's cart becomes a placed order, and staff check every line
 * (full, partial, not available) before it turns into a memo. Stock is only taken at that check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('placed_at')->nullable()->after('last_activity_at');
            $table->timestamp('verified_at')->nullable()->after('placed_at');
            $table->index(['channel', 'status']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->decimal('requested_quantity', 12, 2)->nullable()->after('quantity');
            $table->string('verify_status', 12)->nullable()->after('requested_quantity'); // pending | full | partial | unavailable
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn(['requested_quantity', 'verify_status']));
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropIndex(['channel', 'status']);
            $t->dropColumn(['placed_at', 'verified_at']);
        });
    }
};
