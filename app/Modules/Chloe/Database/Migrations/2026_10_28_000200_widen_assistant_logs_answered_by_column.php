<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PART B.7: two new answered_by values, 'model_guidance' and
 * 'model_compose' (14 and 13 characters), do not fit the original
 * VARCHAR(10) -- widened to 20, comfortably covering every value this
 * column will ever hold. Metadata only, same as the table itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->string('answered_by', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_logs', function (Blueprint $table) {
            $table->string('answered_by', 10)->change();
        });
    }
};
