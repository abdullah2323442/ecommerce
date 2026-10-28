<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether the distance on a food order was the real road distance or the estimate that stands in without a map service.
        Schema::table('food_orders', function (Blueprint $table) {
            $table->boolean('distance_by_road')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('food_orders', fn (Blueprint $table) => $table->dropColumn('distance_by_road'));
    }
};
