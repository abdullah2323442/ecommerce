<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old records kept apart (spec ch 28, note 11: searchable, but not burdening daily work). The whole row is kept as it was.
        Schema::create('archived_records', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);                       // activity | login | notice
            $table->unsignedBigInteger('source_id');
            $table->dateTime('occurred_at')->index();
            $table->text('summary');                            // the words a search looks through
            $table->json('payload');
            $table->dateTime('archived_at');

            $table->unique(['source', 'source_id']);
            $table->index(['source', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_records');
    }
};
