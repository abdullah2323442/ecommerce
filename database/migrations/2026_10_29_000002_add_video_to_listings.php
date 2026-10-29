<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 25: product management has a product video, one short clip a seller adds so a buyer can see the real thing. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->string('video_path')->nullable());
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn('video_path'));
    }
};
