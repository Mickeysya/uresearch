<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The faculty a department belongs to (display and grouping only; our own table). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidacy_departments', function (Blueprint $table) {
            $table->string('faculty', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('candidacy_departments', function (Blueprint $table) {
            $table->dropColumn('faculty');
        });
    }
};
