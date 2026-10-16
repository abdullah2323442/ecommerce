<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 03, 26: a second step at sign-in (a code from an authenticator app). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();               // encrypted with the app key
            $table->text('two_factor_recovery_codes')->nullable();       // a json list of hashes, each used once
            $table->timestamp('two_factor_confirmed_at')->nullable();    // null while setting up: it only counts once a code has been proven
            $table->unsignedBigInteger('two_factor_last_step')->nullable();   // the last 30-second step used, so one code cannot be used twice
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']));
    }
};
