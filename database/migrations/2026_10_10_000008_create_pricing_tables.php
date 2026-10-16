<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec ch 04: the four pricing layers. Priority (highest first):
     * customer manual rate > medicine override > company rate > tier default.
     */
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tier_id')->nullable()->after('account_type')->constrained('tiers')->nullOnDelete();
        });

        Schema::create('company_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('discount_percent', 5, 2);
            $table->timestamps();
        });

        Schema::create('medicine_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('fixed_price', 12, 2)->nullable(); // taka entry; converted to % on read
            $table->timestamps();
        });

        Schema::create('customer_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->decimal('fixed_price', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'medicine_id']);
        });

        Schema::create('tier_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('to_tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_changes');
        Schema::dropIfExists('customer_rates');
        Schema::dropIfExists('medicine_rates');
        Schema::dropIfExists('company_rates');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tier_id');
        });
        Schema::dropIfExists('tiers');
    }
};
