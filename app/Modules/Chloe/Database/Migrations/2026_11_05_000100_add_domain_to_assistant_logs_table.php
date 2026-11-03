<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which assistant domain answered ("workstation" or "candidacy"). Metadata only, like every other column here: never any question or answer text. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->string('domain', 20)->default('workstation')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};
