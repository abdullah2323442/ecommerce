<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Help board (spec ch 19): people post what they need, we approve it first, others answer in comments.
        Schema::create('help_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 12);          // worker | product | help
            $table->string('title', 120);
            $table->text('body');
            $table->string('contact', 120);          // how to reach them, written by them
            $table->string('status', 10)->default('pending');   // pending | open | rejected | closed
            $table->string('review_note', 300)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();   // last time the poster said it is still needed
            $table->timestamp('asked_at')->nullable();       // when "Still needed?" was asked
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 10)->nullable();     // owner | expired | admin
            $table->boolean('hidden')->default(false);       // taken down after reports, until we look
            $table->timestamps();

            $table->index(['status', 'category']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('help_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 600);
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->index('help_post_id');
        });

        Schema::create('help_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['help_post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_reports');
        Schema::dropIfExists('help_comments');
        Schema::dropIfExists('help_posts');
    }
};
