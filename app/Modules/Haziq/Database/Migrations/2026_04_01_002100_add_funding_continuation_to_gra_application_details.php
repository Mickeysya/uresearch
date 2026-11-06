<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extension-only, same as previous_allowance/extension_reason — null on
     * a 'new' row. The official Extension Form asks whether the extension
     * continues under the same grant or is now funded by a new one, which a
     * 'new' Requisition Form never asks because there is no "previous"
     * grant yet to compare against.
     */
    public function up(): void
    {
        Schema::table('gra_application_details', function (Blueprint $table) {
            $table->string('funding_continuation', 20)->nullable()->after('extension_reason');
        });
    }

    public function down(): void
    {
        Schema::table('gra_application_details', function (Blueprint $table) {
            $table->dropColumn('funding_continuation');
        });
    }
};
