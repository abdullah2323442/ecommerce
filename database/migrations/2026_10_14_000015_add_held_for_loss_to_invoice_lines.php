<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            // The invoice's % would sell this line at a loss, so it kept its old price and waits for its own lower % (spec 6.2).
            $table->boolean('held_for_loss')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn('held_for_loss'));
    }
};
