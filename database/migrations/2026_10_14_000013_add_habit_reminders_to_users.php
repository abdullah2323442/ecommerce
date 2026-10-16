<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A buyer can turn the "time to order" reminder off (spec ch 7). The date stops it going twice in a day.
            $table->boolean('habit_reminders')->default(true);
            $table->date('habit_reminded_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['habit_reminders', 'habit_reminded_on']));
    }
};
