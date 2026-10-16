<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shop expenses for net profit (spec 4.3.9) and a per-medicine reorder level for the low-stock alert. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on');
            $table->string('category', 40);
            $table->decimal('amount', 14, 2);
            $table->string('note', 300)->nullable();
            // A recurring expense counts once in every month from its start date until `recurring_until` (or for ever).
            $table->boolean('recurring')->default(false);
            $table->date('recurring_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('spent_on');
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->decimal('reorder_level', 12, 2)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn('reorder_level');
        });
        Schema::dropIfExists('expenses');
    }
};
