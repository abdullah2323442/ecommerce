<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buy more, pay less for each: the same price for everyone, a bigger discount at higher quantities (spec ch 4).
        Schema::create('quantity_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->string('unit', 8);                 // box | strip | piece: the unit the quantity is counted in
            $table->unsignedInteger('min_qty');        // from this many of that unit
            $table->string('kind', 8);                 // percent | taka (off each unit)
            $table->decimal('value', 10, 2);
            $table->timestamps();

            $table->unique(['medicine_id', 'unit', 'min_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quantity_steps');
    }
};
