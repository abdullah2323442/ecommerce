<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A restaurant that sells on the platform (spec ch 17). Fast food only; we take a commission we set by hand, per restaurant.
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 250)->nullable();
            $table->string('area', 80);                              // where it is
            $table->json('areas');                                   // the areas it delivers to (its own included)
            $table->string('status', 10)->default('active');         // active | suspended
            $table->string('commission_kind', 8)->default('percent');   // percent | taka (per delivered order)
            $table->decimal('commission_value', 10, 2)->default(0);
            $table->boolean('is_open')->default(true);               // the switch the owner flips
            $table->string('opens_at', 5)->default('10:00');
            $table->string('closes_at', 5)->default('22:00');
            $table->json('closed_days')->nullable();                 // 0 = Sunday ... 6 = Saturday
            $table->string('delivery_by', 8)->default('own');        // own | platform
            $table->string('charge_mode', 8)->default('area');       // area | manual
            $table->decimal('charge_inside', 8, 2)->default(30);
            $table->decimal('charge_outside', 8, 2)->default(60);
            $table->decimal('min_charge', 8, 2)->default(0);
            $table->boolean('eat_there')->default(true);
            $table->decimal('advance_over', 10, 2)->nullable();      // an order this big needs an advance
            $table->unsignedTinyInteger('advance_percent')->default(0);
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('food_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->decimal('price', 10, 2);
            $table->string('photo_path')->nullable();
            $table->unsignedSmallInteger('cook_minutes')->default(15);
            $table->boolean('ready_made')->default(false);           // ready-made, or needs cooking time
            $table->boolean('available')->default(true);             // on or off at any time
            $table->date('sold_out_on')->nullable();                 // "sold out today": back on by itself tomorrow
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'available']);
        });

        Schema::create('food_orders', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 40)->unique();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 10);                              // delivery | eat_there
            $table->string('status', 10)->default('pending');        // pending | confirmed | packing | out | ready | delivered | cancelled
            $table->json('ship_to');                                 // name, phone, area, address
            $table->string('eat_time', 30)->nullable();              // "in 30 minutes", optional
            $table->string('note', 300)->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_charge', 8, 2)->default(0);
            $table->boolean('charge_open')->default(false);          // the owner types the charge when confirming
            $table->decimal('total', 10, 2);
            $table->decimal('commission', 10, 2)->default(0);        // counted once it is delivered
            $table->decimal('advance_due', 10, 2)->default(0);
            $table->decimal('advance_paid', 10, 2)->default(0);
            $table->unsignedSmallInteger('cook_minutes')->nullable();   // the owner's word, when confirming
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('ready_at')->nullable();               // when the timer on the buyer's screen ends
            $table->timestamp('packing_at')->nullable();
            $table->timestamp('out_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 8)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->timestamp('nudged_at')->nullable();             // told "not approved yet, cancel?"
            $table->string('complaint', 500)->nullable();
            $table->string('complaint_photo')->nullable();
            $table->string('complaint_reply', 300)->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['buyer_id']);
        });

        Schema::create('food_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('quantity');
            $table->string('note', 120)->nullable();                 // "no onion"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_order_items');
        Schema::dropIfExists('food_orders');
        Schema::dropIfExists('food_items');
        Schema::dropIfExists('restaurants');
    }
};
