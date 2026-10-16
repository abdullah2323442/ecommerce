<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 15: when the owner opens a sector they choose which fields it needs (colour, size, measurements, weight, expiry, warranty). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->json('fields')->nullable()->after('allow_fraction');       // null: the older default (colour, size, measurements)
            $table->boolean('custom_orders')->default(false)->after('chat');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->decimal('item_weight', 10, 3)->nullable()->after('item_size_unit');    // kg
            $table->date('expires_on')->nullable()->after('item_weight');
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('expires_on');
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['item_weight', 'expires_on', 'warranty_months']));
        Schema::table('sectors', fn (Blueprint $t) => $t->dropColumn(['fields', 'custom_orders']));
    }
};
