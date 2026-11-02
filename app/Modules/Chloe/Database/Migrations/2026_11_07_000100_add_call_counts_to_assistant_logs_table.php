<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How many HTTP requests were really sent to each model for this one question (retries and failed attempts included), so the day counter can be reconciled with the log exactly. Counts only: no text. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('calls_primary')->default(0)->after('route');
            $table->unsignedTinyInteger('calls_fallback')->default(0)->after('calls_primary');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->dropColumn(['calls_primary', 'calls_fallback']);
        });
    }
};
