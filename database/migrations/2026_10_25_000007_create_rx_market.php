<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The prescription marketplace (item 577, kept ready for later): a person sends a prescription, connected pharmacies answer with a price, the person
        // picks one. Only the structure is built now; people can say they want it, and nothing is matched yet.
        Schema::create('rx_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20)->nullable();
            $table->string('area', 80)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('note', 300)->nullable();
            $table->string('status', 10)->default('open');         // open | answered | closed
            $table->timestamps();
        });

        Schema::create('rx_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rx_request_id')->constrained('rx_requests')->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('note', 300)->nullable();
            $table->string('status', 10)->default('offered');      // offered | chosen | declined
            $table->timestamps();

            $table->unique(['rx_request_id', 'pharmacy_id']);
        });

        // People who said they want it, so the owner knows how many before building it.
        Schema::create('rx_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('phone', 20)->unique();
            $table->string('area', 80)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rx_interests');
        Schema::dropIfExists('rx_offers');
        Schema::dropIfExists('rx_requests');
    }
};
