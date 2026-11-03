<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            // local | international. Only a conference has one; a journal
            // leaves it null. It decides how many internal reviewers must
            // sign a Paper Evaluation Sheet: two for a local conference,
            // three for an international one (a journal takes two).
            $table->string('conference_scope', 20)->nullable()->after('type_of_request');
        });
    }

    public function down(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn('conference_scope');
        });
    }
};
