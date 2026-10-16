<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 13, 20: anyone can order without an account (name, phone and address are required), a signed-in buyer keeps
 * an address book, and the buyer can follow the order from a private link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_token', 40)->nullable()->unique()->after('number');   // the private link to follow an order
            $table->json('ship_to')->nullable()->after('note');                           // name, phone, district, area, address, how to find, for whom
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('district', 40);
            $table->string('area', 80);
            $table->string('address', 250);
            $table->string('how_to_find', 200)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'ship_to']);
        });
    }
};
