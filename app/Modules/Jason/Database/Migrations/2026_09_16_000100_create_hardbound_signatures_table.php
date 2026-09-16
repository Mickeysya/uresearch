<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One signature image per approver, stamped onto the Confirmation of
        // Correction to Thesis at the moment they approve. Lives in this
        // module's own table rather than on `users` (hard rule 2), and the
        // image file sits on the private disk, never under public/.
        Schema::create('hardbound_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 150);
            $table->string('mime_type', 50);
            $table->timestamps();
        });

        // What the student declares on the Confirmation of Correction: the
        // corrections made in response to the examiners. The form is now
        // generated from this rather than uploaded.
        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            $table->text('corrections_made')->nullable()->after('supervisor_name');
        });
    }

    public function down(): void
    {
        Schema::table('hardbound_submission_details', function (Blueprint $table) {
            $table->dropColumn('corrections_made');
        });

        Schema::dropIfExists('hardbound_signatures');
    }
};
