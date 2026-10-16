<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 25: product management has SEO info — the title and description search engines and link previews show. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $t) {
            $t->string('seo_title', 70)->nullable();
            $t->string('seo_description', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['seo_title', 'seo_description']));
    }
};
