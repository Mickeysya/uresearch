<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an examiner's email still works, and when someone last vouched
 * for it. External examiners move and their universities do not tell CGS;
 * until now Non-Executive CGS only learnt an address was dead when an AE
 * reported a bounce.
 *
 * email_status        ok | invalid ("missing" is derived from a null email)
 * email_flagged_at    when it was reported not working
 * email_confirmed_at  when it was last added, changed or confirmed
 *
 * Existing examiners with an email are confirmed as of this migration, so
 * the pool does not open with every address "not confirmed in 12 months".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examiners', function (Blueprint $table) {
            $table->string('email_status', 10)->default('ok')->after('email');
            $table->timestamp('email_flagged_at')->nullable()->after('email_status');
            $table->timestamp('email_confirmed_at')->nullable()->after('email_flagged_at');
        });

        DB::table('examiners')->whereNotNull('email')->update(['email_confirmed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('examiners', function (Blueprint $table) {
            $table->dropColumn(['email_status', 'email_flagged_at', 'email_confirmed_at']);
        });
    }
};
