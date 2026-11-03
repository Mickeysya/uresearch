<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automatic checks on a softbound attempt (Support\SoftboundChecks):
 * what was read from the Turnitin report, and the Pass / Warning / Not
 * checked results the AE sees in the "System checks" panel. One row per
 * attempt; the heavy part runs in a queued job, so status says whether it
 * has finished.
 *
 * Also records, on the attempt itself, where its similarity figure came
 * from and what the report said, so a declared-vs-read mismatch stays
 * visible even if the checks are re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viva_softbound_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('applications')->cascadeOnDelete();
            $table->string('status', 20)->default('queued')->comment('queued | done | failed');
            $table->json('similarity')->nullable()->comment('SimilarityReading::toArray()');
            $table->json('results')->nullable()->comment('[{key, label, status, detail}]');
            $table->string('engine', 40)->nullable()->comment('the PDF reader that ran');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('viva_softbound_details', function (Blueprint $table) {
            $table->string('similarity_source', 20)->nullable()->after('similarity_percent')
                ->comment('extracted | self_declared | simulated');
            $table->decimal('similarity_extracted', 5, 2)->nullable()->after('similarity_source');
        });
    }

    public function down(): void
    {
        Schema::table('viva_softbound_details', function (Blueprint $table) {
            $table->dropColumn(['similarity_source', 'similarity_extracted']);
        });

        Schema::dropIfExists('viva_softbound_checks');
    }
};
