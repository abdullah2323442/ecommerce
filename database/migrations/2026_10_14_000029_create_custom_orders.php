<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The woods and fabrics a buyer can choose, each with its own rate (spec ch 16: the price changes by wood).
        Schema::create('custom_materials', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);                      // wood | fabric
            $table->string('name', 60);
            $table->string('name_bn', 60)->nullable();
            $table->decimal('rate', 10, 2);                  // taka per cubic foot (wood) or per metre (fabric)
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // A buyer's saved measurements, by name: "Mine", "My son's" (spec ch 16.3).
        Schema::create('measurement_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('kind', 10);                      // men | women | wood
            $table->string('unit', 6)->default('inch');
            $table->json('values');
            $table->date('measured_on');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('custom_orders', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 40)->unique();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 10);                      // wood | clothing
            $table->foreignId('material_id')->nullable()->constrained('custom_materials')->nullOnDelete();
            $table->string('material_name', 60)->nullable(); // kept as it was when ordered
            $table->decimal('material_rate', 10, 2)->nullable();
            $table->string('fabric_by', 6)->nullable();      // shop | buyer
            $table->json('items');                           // the measurements as given, one entry per piece or per person
            $table->json('details')->nullable();             // polish, drawers, colour, collar ...
            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();       // the space, or the design
            $table->boolean('urgent')->default(false);
            $table->string('status', 12)->default('requested');   // requested | priced | confirmed | making | trial | done | collected | cancelled
            $table->decimal('material_cost', 12, 2)->nullable();
            $table->decimal('wages_total', 12, 2)->nullable();
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('urgent_fee', 12, 2)->default(0);
            $table->decimal('price', 12, 2)->nullable();      // what the buyer pays in all
            $table->unsignedTinyInteger('advance_percent')->nullable();
            $table->decimal('advance_paid', 12, 2)->default(0);
            $table->unsignedSmallInteger('days_needed')->nullable();
            $table->date('due_on')->nullable();
            $table->string('memo_note', 300)->nullable();
            $table->timestamp('priced_at')->nullable();
            $table->timestamp('buyer_confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 8)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('complaint', 500)->nullable();
            $table->string('complaint_status', 10)->nullable();   // open | fixed | refunded | buyer
            $table->string('complaint_note', 300)->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
            $table->index('status');
        });

        // Who gave which measurements when, who confirmed what: the screen keeps a record (spec ch 16.7).
        Schema::create('custom_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained('custom_orders')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24);
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_events');
        Schema::dropIfExists('custom_orders');
        Schema::dropIfExists('measurement_profiles');
        Schema::dropIfExists('custom_materials');
    }
};
