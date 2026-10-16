<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 9.2: a company's offer passed on to every paikari buyer as a temporary rate on one medicine. It lives for
 * the month (or a shorter duration), stops when stock runs out and picks up again with new stock inside the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tier_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->decimal('percent', 5, 2);                    // discount off MRP given to every paikari buyer
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_on')->default(true);             // the admin can switch it off at any time
            $table->timestamp('decided_at')->nullable();         // after the month: kept running or left off
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['medicine_id', 'is_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_offers');
    }
};
