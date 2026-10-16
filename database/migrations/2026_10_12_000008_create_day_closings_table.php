<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** End of day (spec 4.3.9): the cash the till should hold against the cash counted, saved per day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_closings', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->decimal('expected_cash', 14, 2);
            $table->decimal('counted_cash', 14, 2);
            $table->decimal('difference', 14, 2);
            $table->string('note', 300)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closings');
    }
};
