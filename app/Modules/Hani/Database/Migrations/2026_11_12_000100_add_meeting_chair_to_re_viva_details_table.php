<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The viva chairman for the consolidation meeting, typed by the Senior
 * Executive when the linked viva has none on record (UAT 11 Nov 2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->string('meeting_chair_name', 150)->nullable()->after('meeting_venue');
            $table->string('meeting_chair_email', 150)->nullable()->after('meeting_chair_name');
        });
    }

    public function down(): void
    {
        Schema::table('re_viva_details', function (Blueprint $table) {
            $table->dropColumn(['meeting_chair_name', 'meeting_chair_email']);
        });
    }
};
