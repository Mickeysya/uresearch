<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_authors', function (Blueprint $table) {
            // Where the author's signing link is sent. Nullable only because
            // rows filed before online certification have none.
            $table->string('email')->nullable()->after('author_name');

            // The Authorship Certification Form (signed online, one row per
            // author) instead of a scanned upload. signed_at is the record;
            // the image is what is printed on the generated PDF.
            $table->text('conflict_disclosure')->nullable();
            $table->mediumText('signature_png')->nullable(); // base64, no data: prefix
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->timestamp('invited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('publication_authors', function (Blueprint $table) {
            $table->dropColumn(['email', 'conflict_disclosure', 'signature_png', 'signed_at', 'signed_ip', 'invited_at']);
        });
    }
};
