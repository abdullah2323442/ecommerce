<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Booking a parcel with a courier company (spec ch 20): which company a courier is, and the parcel's number and progress. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('name');           // steadfast | pathao | redx: book parcels through them
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('courier_consignment', 60)->nullable()->index()->after('tracking');   // the company's own number for the parcel
            $table->string('courier_status', 30)->nullable()->after('courier_consignment');
            $table->timestamp('courier_status_at')->nullable()->after('courier_status');
            $table->timestamp('courier_booked_at')->nullable()->after('courier_status_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['courier_consignment', 'courier_status', 'courier_status_at', 'courier_booked_at']));
        Schema::table('couriers', fn (Blueprint $t) => $t->dropColumn('provider'));
    }
};
