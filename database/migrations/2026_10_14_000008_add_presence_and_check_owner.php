<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 7 (staff concurrency): several staff can work on one order. It shows who is working on it, and when two click the same
 * line the first click wins and the other is told who did it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('working_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('working_at')->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('checked_by'));
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn('checked_at'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('working_by'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('working_at'));
    }
};
