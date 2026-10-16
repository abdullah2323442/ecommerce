<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The owner edits the look and words of the site, and connects outside services, from the admin panel (no code, no .env). */
return new class extends Migration
{
    public function up(): void
    {
        // One row per group (brand, contact, social, footer, home, announcement, seo). Missing keys fall back to config/site.php.
        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('group', 40)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One row per outside service, e.g. sms:http, courier:steadfast, payment:bkash. Secrets are encrypted at rest (APP_KEY) and never sent back to a page.
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();                  // type:provider
            $table->string('type', 20)->index();                  // sms | payment | courier | map | social | auth
            $table->boolean('enabled')->default(false);
            $table->string('mode', 10)->default('test');          // test | live
            $table->text('credentials')->nullable();              // encrypted json: the secret fields
            $table->json('settings')->nullable();                 // plain json: the non-secret fields
            $table->timestamp('tested_at')->nullable();
            $table->boolean('test_ok')->nullable();
            $table->string('test_note', 300)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('site_settings');
    }
};
