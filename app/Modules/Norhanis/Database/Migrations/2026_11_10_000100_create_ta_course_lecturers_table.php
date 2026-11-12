<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The staff code that goes with each course lecturer's name. Students
     * find their lecturer on the Daily Log by name or code ("Dr. Hafiz
     * Rahman (UTP1234)"), as in UCampus. Kept here rather than as a column
     * on users, which no module may add to.
     */
    public function up(): void
    {
        Schema::create('ta_course_lecturers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('staff_code', 30)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ta_course_lecturers');
    }
};
