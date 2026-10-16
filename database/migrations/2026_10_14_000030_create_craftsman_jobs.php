<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->foreignId('craftsman_id')->nullable()->constrained('users')->nullOnDelete();      // the one mainly responsible
            $table->foreignId('wage_asked_id')->nullable()->constrained('users')->nullOnDelete();      // asked to say what the wage should be
            $table->decimal('wage_proposed', 12, 2)->nullable();
            $table->string('wage_proposal_note', 300)->nullable();
            $table->string('waiting_note', 200)->nullable();                                          // "Materials needed"
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('progress_nudged_at')->nullable();
            $table->timestamp('late_notified_at')->nullable();
            $table->unsignedSmallInteger('delays')->default(0);
            $table->decimal('advance_given', 12, 2)->default(0);                                       // of the advance, what went to the craftsman
        });

        // Who is on a job. The one mainly responsible decides the split; someone who left keeps what they earned but no longer sees the job.
        Schema::create('custom_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained('custom_orders')->cascadeOnDelete();
            $table->foreignId('craftsman_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 6);                       // main | helper
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['custom_order_id', 'craftsman_id']);
            $table->index(['craftsman_id', 'active']);
        });

        // Progress and wages are the same percentage: each entry is a share of the job, with a photo so it can be checked.
        Schema::create('custom_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained('custom_orders')->cascadeOnDelete();
            $table->foreignId('craftsman_id')->constrained('users')->cascadeOnDelete();     // who did it
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('percent', 5, 2);
            $table->string('photo_path');
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Assign, shift and share: "Rahim took it, gave it to Karim". Everything stays in history (spec ch 16).
        Schema::create('custom_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained('custom_orders')->cascadeOnDelete();
            $table->string('kind', 8);                       // assign | shift | share
            $table->foreignId('from_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // What we have paid a craftsman, the advance they were given to start included.
        Schema::create('craftsman_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('craftsman_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('custom_order_id')->nullable()->constrained('custom_orders')->nullOnDelete();
            $table->string('kind', 8);                       // advance | wages
            $table->decimal('amount', 12, 2);
            $table->string('note', 200)->nullable();
            $table->date('paid_on');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['craftsman_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('craftsman_payments');
        Schema::dropIfExists('custom_moves');
        Schema::dropIfExists('custom_progress');
        Schema::dropIfExists('custom_members');
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('craftsman_id');
            $table->dropConstrainedForeignId('wage_asked_id');
            $table->dropColumn(['wage_proposed', 'wage_proposal_note', 'waiting_note', 'waiting_since', 'progress_nudged_at', 'late_notified_at', 'delays', 'advance_given']);
        });
    }
};
