<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Captured when CGS marks an examiner Unavailable, shown back on the
        // pool list so a later reviewer knows why -- retired vs. just busy
        // looks the same as a bare is_active flag otherwise. Cleared on
        // reactivation, see ExaminerAdminController::toggleActive().
        Schema::table('examiners', function (Blueprint $table) {
            $table->text('unavailable_reason')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('examiners', function (Blueprint $table) {
            $table->dropColumn('unavailable_reason');
        });
    }
};
