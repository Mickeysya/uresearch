<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automatic-release pipeline (STEP 7): a confirmed request's own record
 * of when it's scheduled to end, and which of the three advance notices
 * have already gone out -- tracked here, on our own table, rather than on
 * the shared student status data, which we only ever read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->date('scheduled_release_date')->nullable()->after('released_at');
            $table->timestamp('release_notice_14_sent_at')->nullable()->after('scheduled_release_date');
            $table->timestamp('release_notice_7_sent_at')->nullable()->after('release_notice_14_sent_at');
            $table->timestamp('release_notice_3_sent_at')->nullable()->after('release_notice_7_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->dropColumn([
                'scheduled_release_date',
                'release_notice_14_sent_at',
                'release_notice_7_sent_at',
                'release_notice_3_sent_at',
            ]);
        });
    }
};
