<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RPD masterlist — one row per student, the thing reminders, appeals and
 * dismissals all read from.
 *
 * This is NOT an application detail table. A candidacy exists whether or not
 * the student ever files anything: it is the clock the other three flows watch.
 * That is why it keys on student_id rather than application_id, and why it is
 * the one table in this module that outlives any single application.
 *
 * `rpd_deadline` is stored rather than recomputed on read. It starts as
 * start date + 8 months (full-time) or + 12 (part-time), but an approved appeal
 * moves it, and after that no formula reproduces it — the extension is part of
 * the record. Recomputing would silently undo every extension ever granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidacies', function (Blueprint $table) {
            $table->id();

            // One candidacy per student. The unique constraint is the rule:
            // a second RPD clock for the same person is always a data error.
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('programme_type', 20)->comment('msc_ft | msc_pt | phd_ft | phd_pt');
            $table->date('candidature_start_date');

            // Indexed: the reminder command's only query filters on this.
            $table->date('rpd_deadline')->index();

            $table->string('status', 20)->default('active')
                ->index()->comment('active | extended | defended | dismissed');

            // Enforces nothing on its own -- RpdAppealController checks it
            // before allowing an appeal. Kept here so the ceiling survives a
            // deleted application; the appeal rows are not the source of truth.
            $table->unsignedSmallInteger('extension_months_used')->default(0);

            $table->date('defended_on')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacies');
    }
};
