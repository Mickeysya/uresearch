<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PART 4 #3: recorded at email-link confirmation, never a checkbox. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('released_at');
            $table->string('terms_edition', 50)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('workstation_requests', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_edition']);
        });
    }
};
