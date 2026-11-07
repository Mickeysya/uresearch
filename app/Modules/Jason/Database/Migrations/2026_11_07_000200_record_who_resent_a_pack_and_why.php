<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent an examiner's pack again, when, and why.
 *
 * A resend posts the documents the Dean approved to an examiner who has
 * already been written to once. That is almost always right -- the first
 * attempt failed -- but it is an action with no record, and "did this go
 * out twice?" was unanswerable afterwards.
 *
 * The last resend only. A full history belongs in its own table, and
 * nobody has asked a question yet that the last one does not answer: in
 * practice a pack is resent once, after a bounce. If that changes, these
 * three columns are what the history table would be seeded from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->foreignId('resent_by_id')->nullable()->after('pack_sent_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resent_at')->nullable()->after('resent_by_id');
            $table->string('resent_reason', 300)->nullable()->after('resent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_examiners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resent_by_id');
            $table->dropColumn(['resent_at', 'resent_reason']);
        });
    }
};
