<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Someone copied my product": a signed-in person reports a listing; we look and remove it or turn the report down (spec ch 14).
        Schema::create('listing_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 300);
            $table->string('status', 10)->default('open');       // open | removed | dismissed
            $table->string('our_note', 300)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->unique(['listing_id', 'reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_reports');
    }
};
