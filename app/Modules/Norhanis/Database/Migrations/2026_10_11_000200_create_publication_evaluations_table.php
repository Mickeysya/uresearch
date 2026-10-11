<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Paper Evaluation Sheet (UTP/CGS/040), filled in online by an
        // internal reviewer instead of printed, signed and uploaded. One row
        // per reviewer asked.
        Schema::create('publication_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_detail_id')->constrained()->cascadeOnDelete();

            // Reviewer 1, 2 or 3. A replacement takes over the position of
            // the reviewer it replaces.
            $table->unsignedTinyInteger('position');
            $table->string('reviewer_name', 150);
            $table->string('reviewer_email');
            $table->timestamp('invited_at')->nullable();

            // The sheet itself: a 1-5 rating and a comment per criterion.
            foreach (['objective', 'originality', 'methodology', 'results'] as $criterion) {
                $table->unsignedTinyInteger("{$criterion}_rating")->nullable();
                $table->text("{$criterion}_comment")->nullable();
            }
            $table->text('additional_comments')->nullable();
            $table->string('recommendation', 20)->nullable(); // recommended | corrections | rejected

            $table->mediumText('signature_png')->nullable(); // base64, no data: prefix
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();

            // The reviewer said they cannot do it.
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 500)->nullable();

            // The student's answer to "recommended with corrections".
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();

            // Set when the student asks someone else instead. The row is
            // kept, not deleted, so a rejection stays on the record.
            $table->timestamp('replaced_at')->nullable();

            $table->timestamps();
        });

        Schema::table('publication_details', function (Blueprint $table) {
            // The sheet has a VENUE line the form never asked for.
            $table->string('venue', 255)->nullable()->after('organizer_publisher');
        });
    }

    public function down(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn('venue');
        });

        Schema::dropIfExists('publication_evaluations');
    }
};
