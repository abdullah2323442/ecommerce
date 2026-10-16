<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Selling a strip or a piece takes a fraction of a box out of stock, so stock quantities keep four decimals. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            foreach (['units_paid', 'units_bonus', 'units_received', 'units_remaining', 'units_expired'] as $column) {
                $table->decimal($column, 14, 4)->default(0)->change();
            }
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('units', 14, 4)->change();
        });

        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->decimal('system_units', 14, 4)->change();
            $table->decimal('counted_units', 14, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing would round away real fractions of a box; this migration is one-way on purpose.
    }
};
