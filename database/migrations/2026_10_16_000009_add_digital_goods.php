<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec ch 13: a digital product is downloaded right after payment, with a limit on how many times and for how long.
        Schema::table('listings', function (Blueprint $table) {
            $table->string('digital_path', 255)->nullable();
            $table->string('digital_name', 120)->nullable();
            $table->unsignedTinyInteger('digital_max')->default(3);
            $table->unsignedSmallInteger('digital_days')->default(7);
        });

        // The limits are copied onto the order when it is paid, so changing them later never changes what a buyer already bought.
        Schema::table('market_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('download_max')->default(0);
            $table->unsignedTinyInteger('downloads')->default(0);
            $table->dateTime('download_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', function (Blueprint $table) {
            $table->dropColumn(['download_max', 'downloads', 'download_until']);
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['digital_path', 'digital_name', 'digital_max', 'digital_days']);
        });
    }
};
