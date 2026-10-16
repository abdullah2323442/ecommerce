<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 27/28: keep what people look for and do from the first day, so recommendations and "viewed but not bought"
 * have something to learn from later. No name, phone or address is stored here; a visitor is a hash of their session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);                                     // search | view | cart | checkout | preorder | listing_view
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor', 40);                                  // a hash of the session, never the session itself
            $table->unsignedBigInteger('subject_id')->nullable();           // the medicine, when there is one
            $table->string('term', 100)->nullable();                        // what was searched
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
            $table->index(['subject_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_events');
    }
};
