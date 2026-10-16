<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec ch 3, 14, 25: a business account is opened only after an application with a licence the admin checks.
 * The applicant is never told which kind of account they will get; the admin decides when approving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('business_name', 150);
            $table->string('business_kind', 20);                 // shop | trader (what they say they are; the admin decides the account)
            $table->json('categories');                          // what they deal in
            $table->string('phone', 30);
            $table->string('address', 300);
            $table->string('license_number', 80)->nullable();
            $table->string('license_path', 255);                 // private disk, never a public URL
            $table->string('license_name', 150)->nullable();
            $table->string('second_path', 255)->nullable();      // for example the drug licence beside the trade licence
            $table->string('second_name', 150)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 12)->default('pending');    // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('business_name', 150)->nullable()->after('name');
            $table->json('categories')->nullable()->after('business_name');       // the categories a trade buyer may see
            $table->decimal('moq', 10, 2)->nullable()->after('categories');       // minimum order quantity per line (boxes)
            $table->boolean('pay_cod')->default(true)->after('moq');
            $table->boolean('pay_advance')->default(true)->after('pay_cod');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_terms', 10)->nullable()->after('payment_method'); // cod | advance, chosen when the order is placed
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('payment_terms'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['business_name', 'categories', 'moq', 'pay_cod', 'pay_advance']));
        Schema::dropIfExists('trade_applications');
    }
};
