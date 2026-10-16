<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec ch 03/26: one account per phone number; account type decides sections and start page.
     * Email stays nullable so phone-first accounts (paikari, staff) can exist.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('account_type', 20)->default('public')->index()->after('phone');
            $table->timestamp('suspended_at')->nullable()->after('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropIndex(['account_type']);
            $table->dropColumn(['phone', 'account_type', 'suspended_at']);
        });
    }
};
