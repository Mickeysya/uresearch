<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saved drafts for GA Extension and GA/GRA Certification.
 *
 * A draft is an `applications` row with status 'draft' (current_stage NULL)
 * plus its detail row -- Core's existing draft support, no new table. A
 * half-finished form can't fill these columns, so they become nullable;
 * "required" now lives in each controller's submit validation instead.
 *
 * `draft_step` remembers which step of the form the student was on when
 * they last saved, so Continue reopens there. Cleared on submit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->date('current_end_date')->nullable()->change();
            $table->date('requested_new_end_date')->nullable()->change();
            $table->text('reason_for_extension')->nullable()->change();
            $table->unsignedTinyInteger('draft_step')->nullable()->after('additional_info');
        });

        Schema::table('ga_certification_details', function (Blueprint $table) {
            $table->string('appointment_type', 10)->nullable()->comment('GA or GRA')->change();
            $table->date('period_start')->nullable()->change();
            $table->date('period_end')->nullable()->change();
            $table->unsignedTinyInteger('draft_step')->nullable()->after('purpose');
        });
    }

    /**
     * Back to NOT NULL. Drafts are the only rows that can hold NULLs here,
     * so they are deleted first (applications cascade to their detail rows
     * and document rows; the stored files of a draft are left on the
     * private disk).
     */
    public function down(): void
    {
        DB::table('applications')
            ->whereIn('module_type', ['ga_extension', 'ga_certification'])
            ->where('status', 'draft')
            ->delete();

        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->dropColumn('draft_step');
            $table->date('current_end_date')->nullable(false)->change();
            $table->date('requested_new_end_date')->nullable(false)->change();
            $table->text('reason_for_extension')->nullable(false)->change();
        });

        Schema::table('ga_certification_details', function (Blueprint $table) {
            $table->dropColumn('draft_step');
            $table->string('appointment_type', 10)->nullable(false)->comment('GA or GRA')->change();
            $table->date('period_start')->nullable(false)->change();
            $table->date('period_end')->nullable(false)->change();
        });
    }
};
