<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "I was looking for this" (spec ch 13, checklist #303): when a search finds nothing, a visitor can
     * tell Siam with one click. Identical requests are grouped by `normalized` so the admin sees counts.
     */
    public function up(): void
    {
        Schema::create('product_requests', function (Blueprint $table) {
            $table->id();
            $table->string('query', 120);
            $table->string('normalized', 120)->index();
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_requests');
    }
};
