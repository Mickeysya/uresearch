<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervision_details', function (Blueprint $table) {
            // Main Supervisor / Co-Supervisor -- which one this request is
            // for. Every row that exists today was decided under the old
            // code, which set users.supervisor_id on every approval with no
            // such distinction, so 'main_supervisor' is backfilled below as
            // an accurate description of what already happened, not a
            // guess. See SupervisionController::decide().
            $table->string('appointment_role')->nullable()->after('justification');

            // New / Existing supervisor for this student. Not knowable for
            // a row that predates this column -- left null, shown as "Not
            // recorded" rather than guessed at.
            $table->string('appointment_type')->nullable()->after('appointment_role');

            // Which chain this application is actually running, captured
            // at submission from config('nureen.supervision.cgs_stage') --
            // see SupervisionWorkflow::stages(). Backfilled to 'manual' so
            // every in-flight row still ends at a real CGS decision once it
            // reaches the end of its chain, rather than silently skipping
            // straight to an automatic letter it was never promised.
            $table->string('cgs_stage_snapshot')->nullable()->after('appointment_type');

            // Captured right before a Main Supervisor approval overwrites
            // users.supervisor_id, so a later revocation can restore it --
            // see SupervisionController::decide()/revokeLetter(). Null for
            // a Co-Supervisor appointment, which never touches
            // users.supervisor_id in the first place.
            $table->foreignId('previous_supervisor_id')->nullable()->after('cgs_stage_snapshot')
                ->constrained('users')->nullOnDelete();

            // The appointment letter, once issued -- same shape as
            // GaCertificationDetail's reference_number/revoked_at/
            // revoked_reason, so the "Issued Appointment Letters" list can
            // follow the same pattern as "Issued Certificates".
            $table->string('letter_reference')->nullable()->unique()->after('previous_supervisor_id');
            $table->timestamp('letter_issued_at')->nullable()->after('letter_reference');
            $table->timestamp('letter_revoked_at')->nullable()->after('letter_issued_at');
            $table->string('letter_revoked_reason')->nullable()->after('letter_revoked_at');

            // Set only when a Main Supervisor letter is revoked after
            // users.supervisor_id has already moved on to someone else --
            // see the "don't overwrite a later appointment" rule in
            // SupervisionController::revokeLetter(). Null in the ordinary
            // case where the restore happens normally.
            $table->string('letter_revoke_note')->nullable()->after('letter_revoked_reason');
        });

        DB::table('supervision_details')->update([
            'appointment_role' => 'main_supervisor',
            'cgs_stage_snapshot' => 'manual',
        ]);
    }

    public function down(): void
    {
        Schema::table('supervision_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_supervisor_id');
            $table->dropColumn([
                'appointment_role', 'appointment_type', 'cgs_stage_snapshot',
                'letter_reference', 'letter_issued_at', 'letter_revoked_at',
                'letter_revoked_reason', 'letter_revoke_note',
            ]);
        });
    }
};
