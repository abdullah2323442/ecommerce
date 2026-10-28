<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Service and coaching booking (item 613): someone who gives a service (a tutor, a repair, a lesson) lists it with times, and a buyer books a time.
        Schema::create('service_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('minutes')->default(60);
            $table->decimal('price', 10, 2)->default(0);           // 0 means "ask the provider"
            $table->string('place', 12)->default('provider');      // provider | online | home
            $table->string('area', 80)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('admin_hidden')->default(false);
            $table->timestamps();

            $table->index(['active', 'admin_hidden']);
        });

        Schema::create('service_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('service_offers')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedSmallInteger('booked')->default(0);
            $table->timestamps();

            $table->unique(['offer_id', 'starts_at']);
        });

        Schema::create('service_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 40)->unique();
            $table->foreignId('offer_id')->constrained('service_offers')->cascadeOnDelete();
            $table->foreignId('slot_id')->constrained('service_slots')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('note', 300)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('status', 10)->default('pending');      // pending | confirmed | declined | cancelled | done
            $table->string('reason', 200)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'status']);
            $table->index(['slot_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_bookings');
        Schema::dropIfExists('service_slots');
        Schema::dropIfExists('service_offers');
    }
};
