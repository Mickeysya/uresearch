<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            // Provisional holding place for whatever the real CGS online
            // form turns out to need beyond the three fixed columns already
            // here -- see config('nureen.ga_extension.additional_fields').
            // Nullable and empty today; nothing reads or writes it until a
            // field is declared in that config array.
            $table->json('additional_info')->nullable()->after('reason_for_extension');
        });
    }

    public function down(): void
    {
        Schema::table('ga_extension_details', function (Blueprint $table) {
            $table->dropColumn('additional_info');
        });
    }
};
