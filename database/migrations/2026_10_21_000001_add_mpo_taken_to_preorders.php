<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 10: once the goods from an MPO have reached us and entered stock, the order is no longer "late", and the stock must not be entered a second time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preorders', fn (Blueprint $t) => $t->timestamp('mpo_taken_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('preorders', fn (Blueprint $t) => $t->dropColumn('mpo_taken_at'));
    }
};
