<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec ch 29 (Phase 2): support tickets. A buyer, with or without an account, writes to the shop about an order or anything else and follows the answer.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 40)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('subject', 120);
            $table->string('topic', 12)->default('other');          // order | payment | delivery | product | account | other
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('open');          // open (waiting for us) | answered (waiting for the buyer) | closed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_message_at');
            $table->string('last_by', 8)->default('buyer');          // buyer | staff
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_message_at']);
            $table->index('user_id');
            $table->index('phone');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('by', 8);                                 // buyer | staff | system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
