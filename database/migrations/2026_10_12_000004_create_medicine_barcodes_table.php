<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Barcodes (spec 4.3.8): several per medicine, each linked once and remembered. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_barcodes');
    }
};
