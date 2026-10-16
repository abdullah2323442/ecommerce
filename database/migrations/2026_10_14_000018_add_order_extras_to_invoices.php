<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // What a buyer can add to an order besides the note (spec ch 23): a voice note, a photo, the time they need it by.
            $table->string('voice_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('wanted_time', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['voice_path', 'photo_path', 'wanted_time']));
    }
};
