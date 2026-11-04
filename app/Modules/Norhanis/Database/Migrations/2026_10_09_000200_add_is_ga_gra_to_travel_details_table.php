<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_details', function (Blueprint $table) {
            // Drives whether the TA Plan step applies to this application.
            $table->boolean('is_ga_gra')->default(false)->after('is_international');
        });
    }

    public function down(): void
    {
        Schema::table('travel_details', function (Blueprint $table) {
            $table->dropColumn('is_ga_gra');
        });
    }
};
