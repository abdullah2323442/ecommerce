<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1, the stock system (spec ch 06). Siam's pharmacy only. Purchase prices live here and nowhere
 * public: no table below is ever sent to a visitor, a paikari buyer or any page outside the admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        // STK-1: unlimited sources, each with a default % below MRP.
        Schema::create('stock_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('type', 20)->default('company'); // company | mpo | shop
            $table->decimal('default_percent', 6, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // STK-2: the two overriding layers above the source default.
        Schema::create('company_purchase_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('percent', 6, 3);
            $table->timestamps();
        });

        Schema::create('medicine_purchase_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('percent', 6, 3);
            $table->timestamps();
        });

        // One row per bought invoice. Two invoices on one day are two invoices (STK-9).
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_source_id')->constrained('stock_sources');
            $table->string('invoice_number', 60)->nullable();
            $table->date('invoice_date');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->index(['stock_source_id', 'invoice_number']);
        });

        // Each purchase line is its own lot with its own taka cost, locked when saved (STK-6).
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('units_paid', 12, 2);
            $table->decimal('units_bonus', 12, 2)->default(0);
            $table->decimal('units_received', 12, 2);
            $table->decimal('units_remaining', 12, 2);
            $table->decimal('unit_cost', 14, 4);            // taka paid, per unit received
            $table->decimal('mrp_unit_at_purchase', 14, 2); // for the record only: stock sells at the current MRP (STK-7)
            $table->decimal('purchase_percent', 7, 3);      // true total % below MRP, bonus included
            $table->string('shelf', 40)->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->index(['medicine_id', 'units_remaining']);
        });

        // Every change to stock, append-only. Signed units: purchases add; sales, expiry and corrections may subtract.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained();
            $table->foreignId('stock_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->string('type', 20); // purchase | sale | correction | expired
            $table->decimal('units', 12, 2);
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->boolean('is_loss')->default(false);
            $table->string('reason', 40)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['medicine_id', 'created_at']);
        });

        // Small global switches (Master Stock Sync lives here).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'stock_movements', 'stock_lots', 'purchase_invoices', 'medicine_purchase_rates', 'company_purchase_rates', 'stock_sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
