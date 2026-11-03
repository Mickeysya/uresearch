<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UTP's official "Appeal for Extension of Candidacy" memo -- the letter this
 * digitised form stands in for -- names Student Name, Student ID and
 * Department against the signature block. Name and ID already exist (the
 * application's own student relation), so this adds only what's missing:
 * Department. Captured here, not read off users.department, for the same
 * reason supervisor_id already is -- see CandidacyAppealController's note on
 * that field. Nullable at the DB level so it stays additive; the "required
 * at submission" rule lives in CandidacyAppealController::appealFormRules().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidacy_appeal_details', function (Blueprint $table) {
            $table->string('department', 150)->nullable()->after('supervisor_id');
        });
    }

    public function down(): void
    {
        Schema::table('candidacy_appeal_details', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
