<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A flash sale: a percent off one listing's seller price, for a window of time. One at a time for each listing.
        Schema::create('listing_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('percent');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->index();
            $table->timestamps();
        });

        // A bundle: a few products of one seller bought together for a percent off.
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 80);
            $table->unsignedTinyInteger('percent');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->unique(['bundle_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('listing_sales');
    }
};
