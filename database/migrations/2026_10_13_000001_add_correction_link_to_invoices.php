<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec ch 22: a correction makes a new memo and keeps the old one, marked Cancelled, in history.
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('corrects_invoice_id')->nullable()->after('print_count')->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('corrects_invoice_id'));
    }
};
