<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The candidacy's own extension bookkeeping, additive: candidacy_expiry_date stays the CURRENT expiry (the
 * Workstation module reads it), original_expiry_date is set once and never changes, extension_months_used is
 * the months granted so far (0..12, enforced by the database). `cumulative_extension_months` (the legacy
 * counter) is left in place and untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_candidacies', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('student_id')->constrained('candidacy_departments')->nullOnDelete();
            $table->date('original_expiry_date')->nullable()->after('candidacy_expiry_date');
            $table->unsignedTinyInteger('extension_months_used')->default(0)->after('cumulative_extension_months');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE study_candidacies ADD CONSTRAINT study_candidacies_extension_used_check CHECK (extension_months_used BETWEEN 0 AND 12)');
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement("CREATE TRIGGER study_candidacies_extension_used_check_{$event} BEFORE {$event} ON study_candidacies BEGIN SELECT RAISE(ABORT, 'study_candidacies_extension_used_check') WHERE NEW.extension_months_used < 0 OR NEW.extension_months_used > 12; END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE study_candidacies DROP CHECK study_candidacies_extension_used_check');
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS study_candidacies_extension_used_check_INSERT');
            DB::statement('DROP TRIGGER IF EXISTS study_candidacies_extension_used_check_UPDATE');
        }

        Schema::table('study_candidacies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['original_expiry_date', 'extension_months_used']);
        });
    }
};
