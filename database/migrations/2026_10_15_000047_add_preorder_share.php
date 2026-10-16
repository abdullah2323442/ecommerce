<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 21: 10 people wanted it, 6 came: whoever ordered first gets it first; the rest are told. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preorder_lines', function (Blueprint $table) {
            $table->decimal('share_quantity', 12, 2)->nullable()->after('received_quantity');   // what the first-come sharing gave this line
        });
    }

    public function down(): void
    {
        Schema::table('preorder_lines', fn (Blueprint $t) => $t->dropColumn('share_quantity'));
    }
};
