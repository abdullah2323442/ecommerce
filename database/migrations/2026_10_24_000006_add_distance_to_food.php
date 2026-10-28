<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the restaurant is, what it charges for each kilometre, and how far it will deliver (spec ch 17, the delivery charge options).
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->decimal('per_km', 8, 2)->default(0);
            $table->decimal('max_km', 5, 1)->nullable();
        });

        // How far the address was when the order was made; the buyer's position itself is not kept.
        Schema::table('food_orders', function (Blueprint $table) {
            $table->decimal('distance_km', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('food_orders', fn (Blueprint $table) => $table->dropColumn('distance_km'));
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn(['lat', 'lng', 'per_km', 'max_km']));
    }
};
