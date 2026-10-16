<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // A sale made at the counter while the internet was down (spec ch 8). The device's own number for it is kept, so a sale that
            // is sent twice is only recorded once, and the owner can see which sales had something to look at.
            $table->string('offline_ref', 80)->nullable()->unique();
            $table->timestamp('offline_at')->nullable();
            $table->json('offline_flags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['offline_ref']);
            $table->dropColumn(['offline_ref', 'offline_at', 'offline_flags']);
        });
    }
};
