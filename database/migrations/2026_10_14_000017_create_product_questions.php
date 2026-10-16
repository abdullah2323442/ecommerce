<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Questions under a product, answered by us, read by everyone (spec ch 23, Q&A).
        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('question', 500);
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->index(['medicine_id', 'answered_at']);
            $table->index(['answered_at', 'hidden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_questions');
    }
};
