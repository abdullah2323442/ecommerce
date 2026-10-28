<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reward points (spec ch 27, extras): a buyer earns points on what they buy and turns them into an Aqua Token. The balance is the sum of the rows.
 * A purchase earns once and is reversed once, so the same order can never pay twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_points', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->integer('points');                    // plus when earned, minus when reversed or turned into a token
            $t->string('kind', 10);                   // earn | reverse | spend
            $t->string('source_type', 10)->nullable(); // invoice | market | return
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('token_id')->nullable()->constrained('aqua_tokens')->nullOnDelete();
            $t->string('note', 160)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'kind', 'source_type', 'source_id']);
            $t->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_points');
    }
};
