<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 13: a central product catalog, so ten sellers of the same phone are one product with ten prices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('name_bn', 150)->nullable();
            $table->string('status', 10)->default('approved');            // pending | approved | rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();                       // why it was turned down
            $table->timestamps();

            $table->index(['sector_id', 'status']);
        });

        Schema::table('sectors', function (Blueprint $table) {
            $table->boolean('catalog_required')->default(false)->after('custom_orders');   // sellers must pick from the catalog
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('catalog_product_id')->nullable()->after('sector_id')->constrained('catalog_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropConstrainedForeignId('catalog_product_id'));
        Schema::table('sectors', fn (Blueprint $t) => $t->dropColumn('catalog_required'));
        Schema::dropIfExists('catalog_products');
    }
};
