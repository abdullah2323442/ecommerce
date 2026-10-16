<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 13: reviews from buyers who really bought (with photos), and more than one photo on a product. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_order_id')->unique()->constrained('market_orders')->cascadeOnDelete();   // one review for one finished order
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('body', 1000)->nullable();
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->index(['listing_id', 'hidden']);
        });

        Schema::create('listing_review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_review_id')->constrained('listing_reviews')->cascadeOnDelete();
            $table->string('path');
        });

        Schema::create('listing_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_photos');
        Schema::dropIfExists('listing_review_photos');
        Schema::dropIfExists('listing_reviews');
    }
};
