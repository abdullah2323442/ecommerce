<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock count mode (spec CNT-1, CNT-2): a pausable session, counted vs system, adjustments approved by the admin only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('all'); // all | company | shelf
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shelf', 40)->nullable();
            $table->string('status', 20)->default('open'); // open | paused | approved | cancelled
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->decimal('difference_value', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->decimal('system_units', 12, 2);   // what the system said when the count started
            $table->decimal('counted_units', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
