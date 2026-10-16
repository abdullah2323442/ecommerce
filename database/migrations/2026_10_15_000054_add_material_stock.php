<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 16: wood used for a made-to-order job comes off the stock, "otherwise accounts won't match". Empty stock means the owner does not count that material. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_materials', fn (Blueprint $t) => $t->decimal('stock', 12, 2)->nullable());
        Schema::table('custom_orders', function (Blueprint $t) {
            $t->decimal('material_qty', 12, 2)->nullable();
            $t->timestamp('material_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', fn (Blueprint $t) => $t->dropColumn(['material_qty', 'material_used_at']));
        Schema::table('custom_materials', fn (Blueprint $t) => $t->dropColumn('stock'));
    }
};
