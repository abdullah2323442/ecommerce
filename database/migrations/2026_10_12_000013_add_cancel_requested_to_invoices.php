<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec 4.4.9: after staff start picking, the shop can only ask to cancel and staff decide.
        Schema::table('invoices', fn (Blueprint $t) => $t->timestamp('cancel_requested_at')->nullable()->after('verified_at'));
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('cancel_requested_at'));
    }
};
