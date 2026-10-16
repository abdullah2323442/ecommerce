<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central medicine database (spec ch 11, Step 0-A). Stock entry may only pick
     * medicines from this table; nobody types a free-form name.
     */
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('brand_name')->index();
            $table->string('generic_name')->nullable()->index();
            $table->string('strength')->nullable();
            $table->string('form')->nullable()->index();   // tablet, capsule, syrup, injection…
            $table->string('volume')->nullable();          // "100 ml" for liquids

            // Unit structure: box -> strips -> pieces.
            $table->string('packaging_type', 10)->default('box'); // box | pcs
            $table->unsignedSmallInteger('strips_per_box')->nullable();
            $table->unsignedSmallInteger('pieces_per_strip')->nullable();

            // MRP (printed retail price), BDT. Taka amounts, never recomputed after import.
            $table->decimal('mrp_piece', 12, 2)->nullable();
            $table->decimal('mrp_strip', 12, 2)->nullable();
            $table->decimal('mrp_box', 12, 2)->nullable();

            // Flags (spec ch 11). Only is_active is known from the source data.
            $table->boolean('prescription_required')->default(false);
            $table->boolean('is_cold_chain')->default(false);
            $table->boolean('is_controlled')->default(false);
            $table->boolean('is_active')->default(true);

            // Image: local file under public/ (preferred) or the remote source as fallback.
            $table->string('image_path')->nullable();
            $table->string('image_source_url', 500)->nullable();

            $table->string('source_url', 500)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'brand_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
