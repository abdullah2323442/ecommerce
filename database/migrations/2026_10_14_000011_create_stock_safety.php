<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff cannot correct stock, but they can report what they found (spec 6.7).
        Schema::create('stock_mismatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained();
            $table->foreignId('reported_by')->constrained('users');
            $table->decimal('found', 12, 4);
            $table->decimal('system_units', 12, 4);
            $table->string('note', 300)->nullable();
            $table->string('status', 12)->default('open'); // open | fixed | dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'medicine_id']);
        });

        // A purchase entry stopped halfway (spec 6.8). It adds nothing to stock; it only holds what was typed.
        Schema::create('stock_entry_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->timestamp('mrp_changed_at')->nullable();
            $table->decimal('mrp_before', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn(['mrp_changed_at', 'mrp_before']));
        Schema::dropIfExists('stock_entry_drafts');
        Schema::dropIfExists('stock_mismatches');
    }
};
