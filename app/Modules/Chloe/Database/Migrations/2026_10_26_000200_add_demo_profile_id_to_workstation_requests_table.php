<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * student_id is a required FK into users (Hard Rule 2's shared table), so
 * a demo student -- who has no users row on purpose -- cannot be the
 * occupant of a WorkstationRequest through that column at all. This is
 * the simplest fix that stays inside this module: student_id becomes
 * nullable, and a second, equally nullable FK into our own
 * demo_student_profiles points there instead. Exactly one of the two is
 * ever set on a given row (enforced in code, not a DB CHECK constraint --
 * this project avoids those for portability between MySQL and the
 * SQLite test connection, same reasoning as the CASE WHEN generated
 * column two migrations up). See Support\StudentDirectory, which every
 * consumer now goes through instead of reading student_id directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->foreignId('demo_profile_id')->nullable()->after('student_id')
                ->constrained('demo_student_profiles')->cascadeOnDelete();
        });

        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
        });

        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demo_profile_id');
        });
    }
};
