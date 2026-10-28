<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The credit ledger (baki khata, spec ch 28 note 12 and ch 06 reports): what customers still owe us, and what we still owe each company.
 * A memo already says Paid or Due; this keeps every later payment as a row, so a customer or a company has a statement and not only a total.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Money a customer paid after the sale, against one memo.
        Schema::create('invoice_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('method', 12);
            $t->string('note', 200)->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('received_at');
            $t->timestamps();
            $t->index(['customer_id', 'received_at']);
        });

        // What we owe a company for a purchase: how much is paid so far, and the day the rest is due.
        Schema::table('purchase_invoices', function (Blueprint $t) {
            $t->decimal('paid_amount', 14, 2)->default(0)->after('total_cost');
            $t->date('due_date')->nullable()->after('paid_amount');
        });

        // Every purchase entered before this existed was paid when it was entered.
        DB::table('purchase_invoices')->update(['paid_amount' => DB::raw('total_cost')]);

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_source_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('method', 12);
            $t->string('note', 200)->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('paid_at');
            $t->timestamps();
            $t->index(['stock_source_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::table('purchase_invoices', fn (Blueprint $t) => $t->dropColumn(['paid_amount', 'due_date']));
        Schema::dropIfExists('invoice_payments');
    }
};
