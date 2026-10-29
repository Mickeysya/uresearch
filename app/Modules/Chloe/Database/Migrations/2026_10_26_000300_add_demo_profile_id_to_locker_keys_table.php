<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Same fix, same reasoning, as the matching migration on workstation_requests. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_keys', function (Blueprint $table) {
            $table->foreignId('demo_profile_id')->nullable()->after('student_id')
                ->constrained('demo_student_profiles')->cascadeOnDelete();
        });

        Schema::table('locker_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('locker_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
        });

        Schema::table('locker_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demo_profile_id');
        });
    }
};
