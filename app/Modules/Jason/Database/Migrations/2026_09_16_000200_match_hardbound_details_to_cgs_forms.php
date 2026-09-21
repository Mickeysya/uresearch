<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The official Confirmation of Correction to Thesis (UTP/CGS/017A)
        // carries no statement by the student -- the reviewers certify. It
        // does carry the viva date and the co-supervisor, which nothing else
        // in the portal records.
        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            $table->dropColumn('corrections_made');
            $table->date('viva_date')->nullable()->after('supervisor_name');
            $table->string('co_supervisor_name', 150)->nullable()->after('viva_date');
        });
    }

    public function down(): void
    {
        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            $table->dropColumn(['viva_date', 'co_supervisor_name']);
            $table->text('corrections_made')->nullable()->after('supervisor_name');
        });
    }
};
