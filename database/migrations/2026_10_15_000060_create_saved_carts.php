<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 27 (Phase 2): an abandoned-cart reminder. A signed-in buyer's cart is kept here as well as in the session, only to know who left one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('items')->default(0);
            $table->timestamp('touched_at');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_carts');
    }
};
