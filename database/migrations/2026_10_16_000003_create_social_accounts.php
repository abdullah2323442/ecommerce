<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sign in with Google or Facebook: which outside account belongs to which person. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_id', 100);
            $table->string('email')->nullable();            // what the provider said, kept only for the person to recognise it
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);        // one Google and one Facebook for a person
        });

        // False for an account made by signing in with Google or Facebook: it has a random password nobody knows, until the person sets one.
        Schema::table('users', fn (Blueprint $table) => $table->boolean('has_password')->default(true)->after('password'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('has_password'));
        Schema::dropIfExists('social_accounts');
    }
};
