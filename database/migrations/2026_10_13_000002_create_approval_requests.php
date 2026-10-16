<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 6.2: when staff hit a limit (a discount above their share, a price below cost) they send "Need admin
 * approval". The admin gets one tap, yes or no. If nobody answers in time the request cancels itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);                                // end_discount | invoice_percent | line_price
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('value', 14, 2);                           // the percent or the price asked for
            $table->string('status', 10)->default('pending');          // pending | approved | rejected | expired
            $table->boolean('applied')->default(false);                // approving applies it, unless the invoice moved on
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('invoice_id');
        });

        // A limit set for one staff member overrides the role-wide setting (spec 6.2 "per role and per staff member").
        Schema::table('users', fn (Blueprint $t) => $t->decimal('discount_limit', 5, 2)->nullable()->after('show_monthly_total'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('discount_limit'));
        Schema::dropIfExists('approval_requests');
    }
};
