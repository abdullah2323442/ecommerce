<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chat in a made-to-order job (spec ch 23): the buyer talks to the maker under our name; we see every chat.
        Schema::create('custom_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained('custom_orders')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 6);                       // buyer | maker | staff
            $table->string('kind', 12);                      // text | photo | voice | measurements | offer
            $table->text('body')->nullable();
            $table->string('file_path')->nullable();
            $table->json('meta')->nullable();                // a measurements card, or an offer and what became of it
            $table->timestamp('unanswered_at')->nullable(); // the buyer wrote and nobody answered in time
            $table->timestamp('created_at')->useCurrent();

            $table->index(['custom_order_id', 'id']);
        });

        // A phone number or a link that someone tried to send: the owner sees who tries again and again.
        Schema::create('custom_chat_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_order_id')->constrained('custom_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 6);
            $table->string('reason', 8);                     // number | link
            $table->string('excerpt', 200);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('custom_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_buyer_read_id')->default(0);
            $table->unsignedBigInteger('chat_maker_read_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', fn (Blueprint $table) => $table->dropColumn(['chat_buyer_read_id', 'chat_maker_read_id']));
        Schema::dropIfExists('custom_chat_blocks');
        Schema::dropIfExists('custom_chat_messages');
    }
};
