<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A menu is read in groups (Burgers, Chicken, Sides), so each dish can name its group. A dish with none sits under "Menu". */
    public function up(): void
    {
        Schema::table('food_items', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('food_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
