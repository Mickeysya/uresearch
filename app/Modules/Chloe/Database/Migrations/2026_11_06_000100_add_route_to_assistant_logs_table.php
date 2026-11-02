<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How a question was routed: static (a chip), refused (before the model), cache, model, fallback_error or fallback_budget. Metadata only: never any question or answer text. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->string('route', 20)->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->dropColumn('route');
        });
    }
};
