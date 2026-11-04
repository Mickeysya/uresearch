<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 3 item h: levels 1-3. The student uploads the final thesis (in
 * DocumentStore, their own document), the AE accepts the corrections, and
 * the Confirmation of Correction is generated for signatures (staff-only,
 * re_viva_staff_documents: it names the examiners).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->timestamp('final_thesis_submitted_at')->nullable()->after('correction_deadline');
            $table->timestamp('corrections_accepted_at')->nullable()->after('final_thesis_submitted_at');
            $table->foreignId('corrections_accepted_by_id')->nullable()->after('corrections_accepted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->dropForeign(['corrections_accepted_by_id']);
            $table->dropColumn(['final_thesis_submitted_at', 'corrections_accepted_at', 'corrections_accepted_by_id']);
        });
    }
};
