<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART A.5 (Round 3): metadata only, same rule as every other column on
 * this table -- model_used names which model actually answered (or null
 * if neither did), error_code is a short HTTP-status-shaped string (e.g.
 * "503", "429") for whichever call failed, or null. Neither can ever
 * hold a question, an answer, a name, a student ID or an email, because
 * neither is ever assigned from any of those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->string('model_used', 60)->nullable()->after('answered_by');
            $table->string('error_code', 10)->nullable()->after('model_used');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->dropColumn(['model_used', 'error_code']);
        });
    }
};
