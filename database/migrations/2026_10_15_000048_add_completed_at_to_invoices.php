<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 6.0: a draft sale (the goods have not reached the buyer) and a complete sale (they have) are kept apart; reports, commission and the tier month count only
 * the complete ones. A counter sale is complete when it is finished, an order we send is complete when it arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('finalised_at');
            $table->index('completed_at');
        });

        // What was already finished: complete if nothing had to be sent, or it was delivered; the day is the delivery day for a sent order.
        DB::table('invoices')->where('status', 'final')->where(function ($q) {
            $q->where('channel', 'counter')->orWhereNotIn('delivery_mode', ['send', 'delivery'])->orWhereNull('delivery_mode')->orWhereNotNull('delivered_at');
        })->update(['completed_at' => DB::raw('coalesce(delivered_at, finalised_at)')]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};
