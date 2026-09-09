<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            // The student the application is ABOUT. They see it on their
            // tracking page and receive every notification.
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            // Who actually filed it. Usually the student themselves, but not
            // always -- an examiner nomination is filed by the supervisor
            // about a candidate. The legacy app overloaded student_id for
            // this, so nominations showed up owned by a supervisor.
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();

            // Which module owns this row. VARCHAR, not ENUM: a teammate adding
            // a module must not have to alter a table five other people share.
            $table->string('module_type', 50)->index();

            // draft | pending | approved | rejected. Deliberately only four.
            // The legacy schema also carried 'endorsed' and 'reviewed', which
            // duplicated what current_stage already said and let the two drift
            // out of sync. Position is current_stage's job; status only says
            // whether the application is open, done, or dead.
            $table->string('status', 20)->default('pending')->index();

            // The Stage::key the application is sitting on. NULL only for drafts.
            $table->string('current_stage', 60)->nullable()->index();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['module_type', 'status', 'current_stage'], 'applications_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
