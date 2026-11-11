<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publication changes from the UAT with CGS (11 Nov 2026):
 *
 *  - A publication has no dates of its own, and the "Venue" field goes.
 *    `conference_start_date`, `conference_end_date` and `venue` are dropped.
 *  - A conference instead carries Travel Details, as a Travel application
 *    does: travel dates, the duration (worked out, never typed), reason,
 *    destination and a contact person while travelling. All nullable: a
 *    journal has none, and the form and the server require them for a
 *    conference only.
 *
 * Existing conferences keep what they had: their conference dates become
 * the travel dates (with the duration worked out the same way Travel does,
 * both days counted), and the venue becomes the destination address. A
 * journal's old dates meant nothing and are not kept. The reason and the
 * contact person did not exist before, so they stay empty on old rows.
 *
 * down() puts the three columns back (nullable, so no row can fail) and
 * copies the travel dates and destination back for conferences.
 */
return new class extends Migration
{
    protected const TRAVEL_COLUMNS = [
        'travel_start_date', 'travel_end_date', 'duration_days', 'reason_for_travel',
        'destination_address', 'contact_person_name', 'contact_person_no', 'contact_person_email',
    ];

    public function up(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->date('travel_start_date')->nullable()->after('cost_centre_other');
            $table->date('travel_end_date')->nullable()->after('travel_start_date');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('travel_end_date');
            $table->string('reason_for_travel', 255)->nullable()->after('duration_days');
            $table->string('destination_address', 255)->nullable()->after('reason_for_travel');
            $table->string('contact_person_name', 150)->nullable()->after('destination_address');
            $table->string('contact_person_no', 30)->nullable()->after('contact_person_name');
            $table->string('contact_person_email', 150)->nullable()->after('contact_person_no');
        });

        DB::table('publication_details')
            ->where('type_of_request', 'publication_conference')
            ->orderBy('id')
            ->get(['id', 'conference_start_date', 'conference_end_date', 'venue'])
            ->each(function ($row) {
                $start = $row->conference_start_date ? Carbon::parse($row->conference_start_date)->startOfDay() : null;
                $end = $row->conference_end_date ? Carbon::parse($row->conference_end_date)->startOfDay() : null;

                DB::table('publication_details')->where('id', $row->id)->update([
                    'travel_start_date' => $start?->toDateString(),
                    'travel_end_date' => $end?->toDateString(),
                    'duration_days' => $start && $end && $end->gte($start) ? (int) $start->diffInDays($end) + 1 : null,
                    'destination_address' => $row->venue ?: null,
                ]);
            });

        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn(['conference_start_date', 'conference_end_date', 'venue']);
        });
    }

    public function down(): void
    {
        Schema::table('publication_details', function (Blueprint $table) {
            $table->string('venue', 255)->nullable()->after('organizer_publisher');
            $table->date('conference_start_date')->nullable();
            $table->date('conference_end_date')->nullable();
        });

        DB::table('publication_details')
            ->where('type_of_request', 'publication_conference')
            ->update([
                'conference_start_date' => DB::raw('travel_start_date'),
                'conference_end_date' => DB::raw('travel_end_date'),
                'venue' => DB::raw('destination_address'),
            ]);

        Schema::table('publication_details', function (Blueprint $table) {
            $table->dropColumn(self::TRAVEL_COLUMNS);
        });
    }
};
