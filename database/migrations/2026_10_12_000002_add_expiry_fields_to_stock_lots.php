<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Expiry management (spec 4.3.7): when a lot was last checked, and what became of expired goods. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable()->after('expiry_date');
            $table->timestamp('expired_at')->nullable()->after('checked_at');
            $table->decimal('units_expired', 12, 2)->default(0)->after('expired_at');
            // pending = expired, not yet dealt with; returned = handed back to the company representative; not_taken = they refused (a loss)
            $table->string('return_status', 20)->nullable()->after('units_expired');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropColumn(['checked_at', 'expired_at', 'units_expired', 'return_status']);
        });
    }
};
