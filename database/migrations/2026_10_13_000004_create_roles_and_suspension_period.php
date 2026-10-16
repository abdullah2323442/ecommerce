<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec ch 26: roles you can build and switch permissions on and off for, and suspension for a set period. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('account_type')->constrained('roles')->nullOnDelete();
            $table->dateTime('suspended_until')->nullable()->after('suspended_at');   // empty means until someone reopens it
            $table->string('suspend_reason', 255)->nullable()->after('suspended_until');
        });

        foreach ((array) config('permissions.defaults') as $name => $perms) {
            DB::table('roles')->insert(['name' => $name, 'permissions' => json_encode($perms), 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['suspended_until', 'suspend_reason']);
        });
        Schema::dropIfExists('roles');
    }
};
