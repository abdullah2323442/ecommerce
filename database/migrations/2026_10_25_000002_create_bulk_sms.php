<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A promotional text to many people (spec ch 27, bulk SMS): what was written, who it was for, and how each one went.
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->text('body');
            $table->json('audience');                              // the filters, so the same people can be described again
            $table->string('audience_label', 255);
            $table->string('status', 12)->default('draft');        // draft | sending | done | cancelled
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('sms_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20);
            $table->string('status', 10)->default('queued');       // queued | sent | failed
            $table->string('note', 120)->nullable();
            $table->dateTime('sent_at')->nullable();

            $table->unique(['campaign_id', 'phone']);
            $table->index(['campaign_id', 'status']);
        });

        // Someone who asked not to get promotions. Order messages are not promotions and still reach them.
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('marketing_optout_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('marketing_optout_at'));
        Schema::dropIfExists('sms_recipients');
        Schema::dropIfExists('sms_campaigns');
    }
};
