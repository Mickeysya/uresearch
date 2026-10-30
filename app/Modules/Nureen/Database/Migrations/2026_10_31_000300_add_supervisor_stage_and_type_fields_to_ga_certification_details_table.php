<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_certification_details', function (Blueprint $table) {
            // The named supervisor this application's new first stage is
            // addressed to -- captured at submission from
            // $student->supervisor_id, the same reasoning as Supervision's
            // requested_supervisor_id: if the student's supervisor changes
            // later, the application still goes to whoever it was
            // addressed to when submitted. Null on every row that exists
            // today; those are all already past where this stage would
            // sit (cgs_verify or senior_director), so it is never read for
            // them -- see CertificationLetterWorkflow::stages().
            $table->foreignId('supervisor_id')->nullable()->after('application_id')
                ->constrained('users')->nullOnDelete();

            // "The remaining fields depend on the type" -- see
            // config('nureen.ga_certification.fields'). All nullable: a GA
            // row never fills the GRA-only columns and vice versa, and
            // every row that exists today predates this split entirely.
            $table->string('course_code')->nullable()->after('appointment_type');
            $table->string('course_name')->nullable()->after('course_code');
            $table->string('course_owner_name')->nullable()->after('course_name');
            $table->string('project_title')->nullable()->after('course_owner_name');
            $table->string('project_leader_name')->nullable()->after('project_title');
            $table->string('grant_code')->nullable()->after('project_leader_name');
            $table->string('semester')->nullable()->after('grant_code');

            // Was the only field describing why the letter is needed;
            // kept, but no longer the only word on it now that the
            // type-specific fields above exist -- see
            // config('nureen.ga_certification.fields') and
            // CertificationController::store(). Every row that exists
            // today already has a value here, so nothing to backfill.
            $table->string('purpose', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ga_certification_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropColumn([
                'course_code', 'course_name', 'course_owner_name',
                'project_title', 'project_leader_name', 'grant_code', 'semester',
            ]);
            $table->string('purpose', 255)->nullable(false)->change();
        });
    }
};
