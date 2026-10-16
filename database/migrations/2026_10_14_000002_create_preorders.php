<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 21: a product that is sold out can be pre-ordered, with a time ("within 3 days"). The pre-order is a draft memo
 * that stays editable until the goods arrive and the real quantities are written in; then it becomes the real order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedTinyInteger('preorder_days')->nullable();     // 2..5 turns pre-order on for the whole company
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->unsignedTinyInteger('preorder_days')->nullable();     // overrides the company: 0 forces it off, 2..5 turns it on
        });

        Schema::create('preorders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('requested');           // requested | accepted | declined | cancelled | fulfilled
            $table->unsignedTinyInteger('promised_days');
            $table->date('due_on')->nullable();                           // set when accepted
            $table->decimal('advance_amount', 12, 2)->default(0);
            $table->string('advance_state', 12)->default('none');         // none | promised | held | refund_due | refunded | kept
            $table->string('reply_note', 200)->nullable();
            $table->string('cancelled_by', 8)->nullable();                // buyer | seller
            $table->string('cancel_note', 200)->nullable();
            $table->unsignedTinyInteger('extended')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('told_at')->nullable();                     // the buyer is told only after the goods arrive
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();   // the real order made on receipt
            $table->timestamps();

            $table->index(['status', 'due_on']);
        });

        Schema::create('preorder_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preorder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->string('unit', 8);
            $table->decimal('quantity', 12, 2);
            $table->decimal('received_quantity', 12, 2)->nullable();      // written in when the goods arrive
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preorder_lines');
        Schema::dropIfExists('preorders');
        Schema::table('medicines', fn (Blueprint $t) => $t->dropColumn('preorder_days'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('preorder_days'));
    }
};
