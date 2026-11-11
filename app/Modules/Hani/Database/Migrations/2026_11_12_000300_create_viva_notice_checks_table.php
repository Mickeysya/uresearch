<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automatic document checks on a Notice of Thesis Submission (UAT
 * 11 Nov 2026): one row per notice, filled by the queued RunNoticeChecks
 * job. Advisory only; nothing here rejects a notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viva_notice_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('applications')->cascadeOnDelete();
            $table->string('status', 20)->default('queued')->comment('queued | done | failed');
            $table->json('results')->nullable()->comment('[{key, label, status, detail}]');
            $table->json('papers')->nullable()->comment('per paper: doi, journal, issn, year, accepted, author_match');
            $table->string('engine', 40)->nullable()->comment('the PDF reader that ran');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viva_notice_checks');
    }
};
