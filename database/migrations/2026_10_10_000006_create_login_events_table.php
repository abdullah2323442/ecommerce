<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Spec ch 26: record of suspicious logins and the per-account device limit. */
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable()->index();
            $table->string('ip', 45);
            $table->string('device_hash', 64)->nullable();
            $table->string('outcome', 20); // success | failed
            $table->boolean('is_suspicious')->default(false)->index();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
