<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_details', function (Blueprint $table) {
            $table->string('contact_person_email', 150)->nullable()->after('contact_person_no');
        });
    }

    public function down(): void
    {
        Schema::table('travel_details', function (Blueprint $table) {
            $table->dropColumn('contact_person_email');
        });
    }
};
