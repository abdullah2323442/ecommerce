<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A trade buyer asks "what price for this much of this?" and we answer with one price and a last day (spec ch 14, custom quotation).
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('request', 500);
            $table->string('quantity', 60)->nullable();
            $table->string('status', 10)->default('requested');   // requested | quoted | accepted | declined | cancelled | expired
            $table->decimal('quoted_total', 12, 2)->nullable();
            $table->string('answer_note', 300)->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
