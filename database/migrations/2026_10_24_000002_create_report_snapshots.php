<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec ch 25 dev note: big reports are prepared overnight and are ready instantly in the morning. One row holds one prepared report. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->string('period', 7);          // the month the report is named for, 2026-10
            $t->unsignedTinyInteger('start_day')->default(1); // the day the month starts on when it was prepared (a setting)
            $t->longText('payload');
            $t->timestamp('prepared_at');
            $t->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['kind', 'period', 'start_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');
    }
};
