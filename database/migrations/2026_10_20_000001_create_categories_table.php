<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every kind of shop (a sector) holds a tree of categories as deep as it needs: Electronics > Audio > Headphones > Over-ear. A product sits in the
     * deepest node that fits it, and shows under every node above it.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('name', 80);
            $table->string('name_bn', 80)->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['sector_id', 'slug']);
            $table->index(['sector_id', 'parent_id']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('sector_id')->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('categories');
    }
};
