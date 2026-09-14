<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * publication_details and publication_authors were created (batch 3) with an
 * older field set, then PublicationDetail/PublicationAuthor's $fillable was
 * corrected to the real spec (see PublicationController::store()) without a
 * matching migration — editing the original create-table migration files did
 * nothing, since Laravel had already recorded them as run. This adds the
 * columns the models actually write and drops the stale ones, several of
 * which are NOT NULL with no default and would otherwise fail every insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn([
                'conference_or_journal_name', 'publication_title', 'event_date',
                'location', 'funding_amount_requested', 'requires_letter_of_undertaking',
            ]);

            $table->string('type_of_request', 30)->after('application_id'); // publication_conference | publication_journal
            $table->string('title_of_paper', 255)->after('type_of_request');
            $table->string('title_of_conference_journal', 255)->after('title_of_paper');
            $table->string('organizer_publisher', 255)->after('title_of_conference_journal');

            $table->decimal('conference_journal_fee', 10, 2)->default(0)->after('organizer_publisher');
            $table->string('currency_type', 10)->default('MYR')->after('conference_journal_fee');
            $table->string('cost_centre', 100)->after('currency_type');

            $table->boolean('wants_letter_of_undertaking')->default(false)->after('cost_centre');

            $table->date('conference_start_date')->after('wants_letter_of_undertaking');
            $table->date('conference_end_date')->after('conference_start_date');
        });

        Schema::table('publication_authors', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_corresponding_author']);

            $table->string('author_name', 150)->after('publication_detail_id');
            $table->string('designation', 150)->nullable()->after('author_name'); // e.g. PhD Student, Co-Supervisor, External Collaborator
            $table->string('organisation', 150)->nullable()->after('designation');
            $table->string('role_contribution', 150)->nullable()->after('organisation');
        });
    }

    public function down(): void
    {
        Schema::table('publication_authors', function (Blueprint $table) {
            $table->dropColumn(['author_name', 'designation', 'organisation', 'role_contribution']);

            $table->string('name', 150);
            $table->boolean('is_corresponding_author')->default(false);
        });

        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn([
                'type_of_request', 'title_of_paper', 'title_of_conference_journal', 'organizer_publisher',
                'conference_journal_fee', 'currency_type', 'cost_centre', 'wants_letter_of_undertaking',
                'conference_start_date', 'conference_end_date',
            ]);

            $table->string('conference_or_journal_name', 255);
            $table->string('publication_title', 255);
            $table->date('event_date');
            $table->string('location', 150)->nullable();
            $table->decimal('funding_amount_requested', 10, 2)->default(0);
            $table->boolean('requires_letter_of_undertaking')->default(false);
        });
    }
};
