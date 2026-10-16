<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 27: a legal record of every sale of a controlled medicine, kept for drug-licence inspections. One row per
 * invoice line; the quantity, date and staff come from the sale itself, the rest is typed at the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->string('buyer_name', 120);
            $table->string('buyer_phone', 30)->nullable();
            $table->string('buyer_address', 250)->nullable();
            $table->string('prescription_no', 60);
            $table->string('doctor_name', 120);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('medicine_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_sales');
    }
};
