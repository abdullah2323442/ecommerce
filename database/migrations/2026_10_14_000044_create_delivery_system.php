<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 20: our own delivery people's list, proof of delivery, the money they hold, and the buyer's rating of the delivery. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('delivery_person_id')->nullable()->after('courier_id')->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->after('delivery_person_id')->constrained('users')->nullOnDelete();   // the staff member who sent it
            $table->timestamp('arrived_at')->nullable()->after('sent_at');
            $table->string('delivery_code', 6)->nullable();                       // the one-time code the buyer tells the delivery person
            $table->unsignedTinyInteger('delivery_code_tries')->default(0);
            $table->string('delivery_photo_path')->nullable();                    // proof when the buyer cannot give the code
            $table->decimal('cash_collected', 12, 2)->nullable();                 // what the delivery person took at the door
            $table->timestamp('cash_handed_at')->nullable();                      // when they gave it to us
        });

        Schema::create('delivery_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_person_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('delivery_stars');
            $table->unsignedTinyInteger('service_stars');
            $table->string('comment', 500)->nullable();
            $table->timestamps();
        });

        // A starting role for our delivery people (spec ch 26: roles are built from permissions).
        if (! Role::where('name', 'Delivery person')->exists()) {
            Role::create(['name' => 'Delivery person', 'permissions' => ['deliveries'], 'is_system' => true]);
        }
    }

    public function down(): void
    {
        Role::where('name', 'Delivery person')->delete();
        Schema::dropIfExists('delivery_ratings');
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('delivery_person_id');
            $t->dropConstrainedForeignId('handled_by');
            $t->dropColumn(['arrived_at', 'delivery_code', 'delivery_code_tries', 'delivery_photo_path', 'cash_collected', 'cash_handed_at']);
        });
    }
};
