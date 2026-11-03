<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Notice of Thesis Submission now carries the thesis abstract and its
 * keywords. They are the query document for the examiner suggestions
 * (Support\Matching\ExaminerSuggestions::queryFor()), which matches them
 * against the pool's expertise and publications.
 *
 * Nullable: notices filed before this change have neither, and the matcher
 * falls back to the title for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viva_details', function (Blueprint $table) {
            $table->text('abstract')->nullable()->after('thesis_title');
            // A JSON list of lower-case terms, 3 to 8 of them.
            $table->json('keywords')->nullable()->after('abstract');
        });
    }

    public function down(): void
    {
        Schema::table('viva_details', function (Blueprint $table) {
            $table->dropColumn(['abstract', 'keywords']);
        });
    }
};
