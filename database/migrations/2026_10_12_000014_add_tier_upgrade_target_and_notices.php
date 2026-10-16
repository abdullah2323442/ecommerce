<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 4.4.10: the monthly business that earns a tier. Empty means the tier is not earned by buying.
        Schema::table('tiers', fn (Blueprint $t) => $t->decimal('upgrade_target', 14, 2)->nullable()->after('discount_percent'));

        // A small inbox for messages to one customer (the upgrade congratulations first; more channels come later).
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('body', 300);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
        Schema::table('tiers', fn (Blueprint $t) => $t->dropColumn('upgrade_target'));
    }
};
