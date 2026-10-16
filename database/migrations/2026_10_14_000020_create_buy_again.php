<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A buyer can switch the "buy again" reminder off (spec ch 13).
            $table->boolean('buy_again')->default(true);
        });

        // When each reminder went, so the same medicine is not reminded again until its own rhythm comes round.
        Schema::create('buy_again_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->timestamp('reminded_at');

            $table->index(['user_id', 'medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buy_again_reminders');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('buy_again'));
    }
};
