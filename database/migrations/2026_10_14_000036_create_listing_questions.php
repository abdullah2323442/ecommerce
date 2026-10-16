<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 23: questions under a seller's product. The seller answers; one left unanswered comes to us, and counts against the seller. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();     // who asked
            $table->string('question', 500);
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('escalated_at')->nullable();                       // the seller was too slow: it came to us
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->index(['listing_id', 'answered_at']);
            $table->index(['seller_id', 'escalated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_questions');
    }
};
