<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('title', 160)->nullable();
            $table->string('title_bn', 160)->nullable();
            $table->string('text', 500)->nullable();
            $table->string('text_bn', 500)->nullable();
            $table->string('button', 60)->nullable();
            $table->string('button_bn', 60)->nullable();
            $table->string('source', 60)->nullable();
            $table->unsignedTinyInteger('item_limit')->default(8);
            $table->string('image_path', 255)->nullable();
            $table->json('items')->nullable();
            $table->timestamps();

            $table->index(['sector_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_blocks');
    }
};
