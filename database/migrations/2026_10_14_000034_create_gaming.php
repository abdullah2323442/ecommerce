<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Game top-up, like Codashop (spec ch 18): a list of games, packages of diamonds for each, a player ID, online payment only.
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('id_label', 40)->default('Player ID');
            $table->boolean('needs_zone')->default(false);          // some games need a server or zone with the ID
            $table->string('zone_label', 40)->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('game_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);                             // "100 Diamonds"
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('game_orders', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 40)->unique();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('game_name', 80);                         // kept as ordered
            $table->string('package_name', 80);
            $table->decimal('price', 10, 2);
            $table->string('player_id', 60);
            $table->string('zone', 40)->nullable();
            $table->string('player_name', 80)->nullable();           // shown by the check, when there is one
            $table->timestamp('id_confirmed_at');                    // the buyer confirmed the ID (and name): after that a mistake is theirs
            $table->string('contact_phone', 20);
            $table->string('status', 16)->default('placed');         // placed | claimed | paid | topped_up | failed | cancelled
            $table->string('pay_method', 10)->nullable();            // bkash | nagad | rocket | card
            $table->string('pay_ref', 40)->nullable();               // the transaction id the buyer gives
            $table->string('pay_note', 200)->nullable();             // why a payment was not accepted
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('topped_up_at')->nullable();
            $table->string('topup_ref', 60)->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('fail_reason', 200)->nullable();
            $table->string('refund', 8)->default('none');           // none | due | done
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('late_told_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('refund');
            $table->unique(['pay_method', 'pay_ref']);              // one transaction pays one order, once
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_orders');
        Schema::dropIfExists('game_packages');
        Schema::dropIfExists('games');
    }
};
