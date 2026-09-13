<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The CGS letter addresses the examiner over several lines -- faculty
        // or department, institution, postcode and country for an external
        // examiner, just the faculty for an internal one. examiner_institution
        // holds a single line, which is enough for the queue screens but not
        // for the letter itself.
        Schema::table('appointment_details', function (Blueprint $table) {
            $table->text('examiner_address')->nullable()->after('examiner_institution');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_details', function (Blueprint $table) {
            $table->dropColumn('examiner_address');
        });
    }
};
