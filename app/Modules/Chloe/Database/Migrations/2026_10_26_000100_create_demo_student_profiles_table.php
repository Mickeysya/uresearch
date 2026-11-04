<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table, owned entirely by this module, for every demo "student" that
 * has no real login account -- it is the marker (there is deliberately no
 * is_demo column anywhere: a row existing here at all means demo). Nothing
 * outside this module reads or writes it. workstation_requests.
 * demo_profile_id and locker_keys.demo_profile_id point here exactly the
 * way their student_id columns point to users, for the rows that belong to
 * a demo profile instead of a real account — see
 * add_demo_profile_id_to_workstation_requests_table / ...locker_keys_table
 * and Support\StudentDirectory for how the two are resolved together.
 *
 * programme_start_date / candidacy_expiry_date / status use the exact
 * field names StudyCandidacy already uses, for a future feature to read --
 * nothing creates an actual StudyCandidacy row for a demo profile (that
 * table's student_id is a required FK into users, and the brief is
 * explicit: no candidacy or appeal records for demo students).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_student_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('matric_no', 30)->unique();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('gender', 10);
            $table->string('programme', 100)->nullable();
            $table->date('programme_start_date')->nullable();
            $table->date('candidacy_expiry_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_student_profiles');
    }
};
