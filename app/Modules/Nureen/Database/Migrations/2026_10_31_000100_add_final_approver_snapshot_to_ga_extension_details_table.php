<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            // Which chain this application is actually running, captured at
            // submission time from config('nureen.ga_extension.final_approver')
            // -- so a later change to that config can never alter where an
            // in-flight application ends up. GaExtensionWorkflow::stages()
            // reads this column, falling back to the live config only when
            // it is null.
            $table->string('final_approver_snapshot')->nullable()->after('reason_for_extension');
        });

        // Every row that exists today was submitted under the only chain
        // that has ever existed for this module -- Supervisor, then CGS
        // Staff, then Senior Director. Backfilling them to
        // 'senior_director' keeps every in-flight application finishing on
        // that same chain once this migration ships; only applications
        // submitted after it are eligible for the new 'cgs' default.
        DB::table('ga_extension_details')->update(['final_approver_snapshot' => 'senior_director']);
    }

    public function down(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->dropColumn('final_approver_snapshot');
        });
    }
};
