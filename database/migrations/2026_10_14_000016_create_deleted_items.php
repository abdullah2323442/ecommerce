<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The "Deleted" bin (spec ch 25): what the owner removed by mistake stays here for 30 days and can be put back.
        Schema::create('deleted_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('label', 160);
            $table->json('payload');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_items');
    }
};
