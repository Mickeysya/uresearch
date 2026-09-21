<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns CGS keeps for an EXTERNAL examiner and does not keep for an
     * internal one. The two are maintained as two separate spreadsheets today:
     * the internal sheet is name / department / availability / remarks, while
     * the external sheet carries the faculty approval reference, the
     * institution, the expertise and the supervision record that justify
     * appointing someone from outside UTP.
     *
     * All nullable: an internal examiner legitimately has none of them, and a
     * newly added external one is often entered before the faculty paper is
     * approved. Examiner::isExternal() is what decides whether they are shown.
     *
     * Not added, deliberately:
     *   "Date 2nd"  -> last_examination_date, which already exists and already
     *                  drives the 90-day gap. A second stored copy would drift.
     *   "Student Name" / internal "Remarks" -> derived from examiner_nominations,
     *                  so the pool cannot disagree with the nominations table.
     *   "Remark"    -> unavailable_reason already carries the only remark the
     *                  system needs to show, and the column is empty in the
     *                  sheets we were given.
     */
    public function up(): void
    {
        Schema::table('examiners', function (Blueprint $table) {
            // "Faculty Approval" -- the paper that approved them, e.g. "2.2023".
            $table->string('faculty_approval', 30)->nullable()->after('faculty');

            // "University/Industry" and what kind of body it is.
            $table->string('institution', 150)->nullable()->after('faculty_approval');
            $table->enum('sector', ['technical', 'research'])->nullable()->after('institution');

            // "Area of Expertise" and the UTP cluster it maps onto.
            $table->text('expertise')->nullable()->after('sector');
            $table->string('utp_cluster', 150)->nullable()->after('expertise');

            // The "Details" cell: experience and graduates supervised.
            $table->unsignedSmallInteger('years_experience')->nullable()->after('utp_cluster');
            $table->unsignedSmallInteger('msc_graduated')->nullable()->after('years_experience');
            $table->unsignedSmallInteger('phd_graduated')->nullable()->after('msc_graduated');

            // "Date 1st". Date 2nd is last_examination_date -- see above.
            $table->date('first_examination_date')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('examiners', function (Blueprint $table) {
            $table->dropColumn([
                'faculty_approval', 'institution', 'sector', 'expertise', 'utp_cluster',
                'years_experience', 'msc_graduated', 'phd_graduated', 'first_examination_date',
            ]);
        });
    }
};
