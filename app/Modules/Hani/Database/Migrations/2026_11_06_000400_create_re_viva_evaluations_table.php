<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 3 item f: each main examiner's Thesis Re-evaluation Report
 * (UTP/CGS/024), recorded by the Academic Executive at stage 3.
 *
 *   category    1 Pass (final thesis within 1 month) · 2 Minor (up to 3
 *               months) · 3 Major (3 to 6 months) · 4 Fail
 *   viva_again  category 4 only: "required to attend the viva again"
 *   ratings     optional, the form's 8 general criteria and 12 thesis
 *               sections, 1 to 5 (the same keys as the viva report)
 *   report_document_id  the signed form, in re_viva_staff_documents
 *
 * Staff only: students never see an examiner's category or name. One row
 * per round and seat; recording again replaces it until stage 3 completes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_viva_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('re_viva_detail_id')->constrained('re_viva_details')->cascadeOnDelete();
            $table->string('seat', 30)->comment('internal_main_id | external_main_id');
            $table->foreignId('examiner_id')->constrained('examiners')->cascadeOnDelete();
            $table->unsignedTinyInteger('category')->comment('1-4, see ReVivaEvaluation::CATEGORIES');
            $table->boolean('viva_again')->nullable();
            $table->json('ratings')->nullable();
            $table->foreignId('report_document_id')->nullable()->constrained('re_viva_staff_documents')->nullOnDelete();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['re_viva_detail_id', 'seat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_viva_evaluations');
    }
};
