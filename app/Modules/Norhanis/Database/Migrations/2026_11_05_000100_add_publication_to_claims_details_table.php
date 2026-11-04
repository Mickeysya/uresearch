<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The approved Publication application a claim pays for. Claims are
        // journal-publication claims only, so every new claim names one --
        // ClaimsController::store() requires it. Nullable for the claims
        // filed before the link existed.
        Schema::table('claims_details', function (Blueprint $table) {
            $table->foreignId('publication_application_id')->nullable()->after('application_id')
                ->constrained('applications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('claims_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publication_application_id');
        });
    }
};
