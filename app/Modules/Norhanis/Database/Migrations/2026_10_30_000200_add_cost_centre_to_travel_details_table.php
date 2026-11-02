<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Cost Centre to be utilized (if any)" on the Travel form: optional, so
 * nullable, and free text because cost centre codes come in several shapes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_details', function (Blueprint $table) {
            $table->string('cost_centre', 100)->nullable()->after('destination_address');
        });
    }

    public function down(): void
    {
        Schema::table('travel_details', function (Blueprint $table) {
            $table->dropColumn('cost_centre');
        });
    }
};
