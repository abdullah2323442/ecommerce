<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The central ingredient list (spec ch 17): ours alone. A restaurant ticks what it has; it cannot type a new name.
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('name_bn', 80)->nullable();
            $table->string('group', 12);                      // meat | cheese | vegetables | sauces | extras | spices
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('group');
        });

        // A restaurant that has an ingredient we do not list asks us to add it; once added, everyone gets it.
        Schema::create('ingredient_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('group', 12)->nullable();
            $table->string('status', 10)->default('pending');   // pending | added | declined
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // What a restaurant has, at its own price. The list is ours; the prices are theirs.
        Schema::create('restaurant_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 8, 2);
            $table->boolean('available')->default(true);
            $table->date('sold_out_on')->nullable();           // "sold out today": back on by itself tomorrow
            $table->timestamps();

            $table->unique(['restaurant_id', 'ingredient_id']);
        });

        // What a buyer builds on: burger, pizza, sandwich, roll.
        Schema::create('food_bases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                        // burger | pizza | sandwich | roll
            $table->string('name', 80);
            $table->decimal('price', 8, 2);
            $table->unsignedSmallInteger('cook_minutes')->default(15);
            $table->boolean('available')->default(true);
            $table->date('sold_out_on')->nullable();
            $table->timestamps();
        });

        // "This does not go with that."
        Schema::create('ingredient_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_a_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('ingredient_b_id')->constrained('ingredients')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['restaurant_id', 'ingredient_a_id', 'ingredient_b_id'], 'rule_pair');
        });

        Schema::create('food_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_base_id')->constrained('food_bases')->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('ingredient_ids');
            $table->timestamps();

            $table->unique(['user_id', 'restaurant_id', 'name']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_ingredients')->default(10);        // a limit, so no burger has fifteen things in it
            $table->unsignedSmallInteger('custom_extra_minutes')->default(10);  // custom food takes longer, and the buyer is told
        });

        Schema::table('food_order_items', function (Blueprint $table) {
            $table->json('ingredients')->nullable();   // a custom item: the base, then each ingredient with its price, as ordered
        });
    }

    public function down(): void
    {
        Schema::table('food_order_items', fn (Blueprint $table) => $table->dropColumn('ingredients'));
        Schema::table('restaurants', fn (Blueprint $table) => $table->dropColumn(['max_ingredients', 'custom_extra_minutes']));
        Schema::dropIfExists('food_favorites');
        Schema::dropIfExists('ingredient_rules');
        Schema::dropIfExists('food_bases');
        Schema::dropIfExists('restaurant_ingredients');
        Schema::dropIfExists('ingredient_requests');
        Schema::dropIfExists('ingredients');
    }
};
