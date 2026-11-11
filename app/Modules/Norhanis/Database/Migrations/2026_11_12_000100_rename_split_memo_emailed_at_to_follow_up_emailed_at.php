<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every journal now gets a follow-up email on final approval, not only the
 * Split Memo: Pay and Claims ("submit your claim") and LOU ("CGS pays
 * directly") too (UAT, 11 Nov 2026). The once-only marker covers all three,
 * so it is renamed for what it now records. See Support\PublicationFollowUp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->renameColumn('split_memo_emailed_at', 'follow_up_emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->renameColumn('follow_up_emailed_at', 'split_memo_emailed_at');
        });
    }
};
