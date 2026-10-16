<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The shop's short list (spec 4.4): medicines it wants to remember, ordered later in one tap. Free feature. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->string('unit', 8)->default('box');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'medicine_id', 'unit']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_list_items');
    }
};
