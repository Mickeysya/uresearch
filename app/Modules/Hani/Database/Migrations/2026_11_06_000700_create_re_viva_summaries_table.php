<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 3 item c2 (nice-to-have): the digital Summary of Corrections.
 *
 * The student fills it in before the corrected thesis goes to CGS: per
 * examiner, each comment verbatim, the action taken and the updated pages.
 * The system checks every comment has an action and every page is within
 * the corrected thesis's page count (read with the built-in PDF reader; the
 * thesis itself is not kept here, only its page count), then renders the
 * PDF. The Senior Executive can attach that PDF when logging the round,
 * instead of uploading one (it then lands in DocumentStore on the round).
 *
 * Examiners are "Examiner 1 (Internal)" and "Examiner 2 (External)": the
 * student never sees their names here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_viva_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->json('rows')->nullable()->comment('[{examiner: 1|2, comment, action, pages}]');
            $table->unsignedInteger('thesis_pages')->nullable();
            $table->string('thesis_name')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('used_by_detail_id')->nullable()->constrained('re_viva_details')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'used_by_detail_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_viva_summaries');
    }
};
