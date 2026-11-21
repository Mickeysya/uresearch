<?php

namespace App\Modules\Nureen\Support\Demo;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Nureen\Models\DemoRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * `nureen:demo-fresh --reset`: deletes exactly what nureen_demo_records
 * lists, and the rows that hang off those applications -- nothing chosen by
 * a pattern or a date, so a real record can never be caught.
 *
 * For each demo application: its stored files, its in-app notifications
 * (their data names the application), its activity-log entries, then the
 * application itself, whose approval history, documents, detail rows and
 * reminders go with it (ON DELETE CASCADE). Then the other rows the
 * command listed: attendance, risk states, alerts, the GA/GRA appointment
 * record, the GA profile and terms acceptance it made through Haziq's form.
 *
 * Emails already delivered to Mailpit stay there; delete them in Mailpit.
 */
class DemoFreshReset
{
    /** Listed tables other than applications, in a safe deletion order. */
    protected const OTHER_TABLES = [
        'attendance_alerts',
        'attendance_risk_states',
        'attendance_records',
        'ga_appointment_records',
        'gra_ga_student_profiles',
        'terms_acceptances',
    ];

    /** @return array<string, int> rows deleted, per table */
    public function run(): array
    {
        $deleted = [];
        $applicationIds = DemoRecord::idsFor('applications');

        if ($applicationIds !== []) {
            $documents = ApplicationDocument::whereIn('application_id', $applicationIds)->get(['id', 'path']);
            $disk = Storage::disk('local');

            foreach ($documents as $document) {
                $disk->delete($document->path);
            }

            foreach ($applicationIds as $id) {
                $disk->deleteDirectory("applications/{$id}");
            }

            $deleted['notifications'] = DB::table('notifications')
                ->where(function ($q) use ($applicationIds) {
                    foreach (array_chunk($applicationIds, 200) as $chunk) {
                        $q->orWhereIn('data->application_id', $chunk);
                    }
                })
                ->delete();

            $activity = config('activitylog.table_name', 'activity_log');
            $deleted[$activity] = DB::table($activity)
                ->where(fn ($q) => $q->where('subject_type', Application::class)->whereIn('subject_id', $applicationIds))
                ->orWhere(fn ($q) => $q->where('subject_type', ApplicationDocument::class)->whereIn('subject_id', $documents->pluck('id')))
                ->delete();

            $deleted['application_documents'] = $documents->count();
            $deleted['applications'] = Application::whereIn('id', $applicationIds)->delete();
        }

        foreach (self::OTHER_TABLES as $table) {
            $ids = DemoRecord::idsFor($table);

            if ($ids !== []) {
                $deleted[$table] = DB::table($table)->whereIn('id', $ids)->delete();
            }
        }

        DemoRecord::query()->delete();

        return array_filter($deleted);
    }
}
