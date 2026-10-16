<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 12: returns for shops under strict rules, credit on the next memo, goods back into their own lot. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();   // none for retail
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // none for "return without invoice"
            $table->string('kind', 10);                                  // paikari | retail
            $table->string('status', 10)->default('requested');          // requested | approved | rejected
            $table->string('resolution', 10);                            // credit | exchange | cash
            $table->string('reason_code', 20)->default('other');
            $table->string('reason', 500);                               // a note is mandatory
            $table->string('voice_path')->nullable();                    // a voice note is allowed too
            $table->string('fault', 8)->nullable();                      // staff | shop, set when approved
            $table->boolean('without_invoice')->default(false);
            $table->decimal('amount', 14, 2)->default(0);                // full value at the price they paid
            $table->decimal('credit_amount', 14, 2)->default(0);         // added to the customer's credit
            $table->decimal('cash_amount', 14, 2)->default(0);           // paid back in cash (50% rule)
            $table->decimal('expected_money', 14, 2)->nullable();        // retail: the optional "will receive money" field
            $table->string('decision_note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_id');
        });

        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->string('unit', 8);
            $table->decimal('quantity', 12, 2);
            $table->decimal('stock_units', 14, 4);
            $table->decimal('unit_price', 14, 2);                        // the price they paid, per returned unit
            $table->decimal('amount', 14, 2);
            $table->boolean('was_offer')->default(false);
            $table->timestamps();
        });

        // Credit lives here, one entry per change. It can only be spent in the sector it was earned in (pharmacy for now).
        Schema::create('credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sector', 20)->default('pharmacy');
            $table->decimal('amount', 14, 2);                            // + earned by a return, - spent on a memo
            $table->string('reason', 60);
            $table->foreignId('sale_return_id')->nullable()->constrained('sale_returns')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'sector']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('credit_applied', 14, 2)->default(0)->after('discount_total'); // "Return product" taken off this memo
            $table->unsignedSmallInteger('print_count')->default(0)->after('credit_applied'); // from the second print the memo says "Reprint"
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['credit_applied', 'print_count']));
        Schema::dropIfExists('credit_entries');
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
    }
};
