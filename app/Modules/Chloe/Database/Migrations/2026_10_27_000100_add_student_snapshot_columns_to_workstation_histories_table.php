<?php

use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Support\StudentDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART 3 #1: student_name and student_matric_no were already here; email
 * and programme were not, so History alone could never show either one.
 * Backfilled once, here, for every row that already has a student_matric_no
 * -- looked up through StudentDirectory exactly the way a live write does
 * it now, covering both a real account and a demo profile. This is the one
 * allowed exception to "History is append-only for every screen and
 * service": a one-time column backfill inside a migration, never a normal
 * write path, and it only ever fills these two new columns -- every other
 * column on every row is left exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_histories', function (Blueprint $table) {
            $table->string('student_email', 150)->nullable()->after('student_matric_no');
            $table->string('student_programme', 100)->nullable()->after('student_email');
        });

        WorkstationHistory::whereNotNull('student_matric_no')
            ->whereNull('student_email')
            ->get(['id', 'student_matric_no'])
            ->each(function (WorkstationHistory $row) {
                $identity = StudentDirectory::findByStudentId($row->student_matric_no);

                if ($identity) {
                    $row->newQuery()->whereKey($row->id)->update([
                        'student_email' => $identity->email,
                        'student_programme' => $identity->programme,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('workstation_histories', function (Blueprint $table) {
            $table->dropColumn(['student_email', 'student_programme']);
        });
    }
};
