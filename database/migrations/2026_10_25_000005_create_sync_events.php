<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What passed between this site and PharmaTrack, one row for each message, so nothing is applied twice and the owner can see what failed (spec ch 6, 7).
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 3);                       // in | out
            $table->string('kind', 12);                           // sale | count | online_sale
            $table->string('ref', 80);                            // the sender's own reference: the same one is never applied twice
            $table->string('status', 10)->default('pending');     // pending | ok | partial | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('summary')->nullable();                  // short words about what it did, not the whole message
            $table->string('error', 300)->nullable();
            $table->json('payload')->nullable();                  // kept for what we send, so a failed one can be sent again
            $table->timestamps();

            $table->unique(['direction', 'kind', 'ref']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
