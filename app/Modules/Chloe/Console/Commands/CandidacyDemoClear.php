<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes exactly what candidacy:demo-seed made: appeals marked is_demo (with their history, approvals, documents, publications,
 * memo and signature-copy files), demo staff profiles (with their placeholder signature files and assignments) and demo
 * departments. Never touches a real account, a real signature file, a real department, or the real people assigned to CIS.
 */
class CandidacyDemoClear extends Command
{
    protected $signature = 'candidacy:demo-clear';

    protected $description = 'Remove the demo appeals, demo staff and demo departments made by candidacy:demo-seed';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $appeals = CandidacyAppeal::where('is_demo', true)->get();
        $profiles = CandidacyStaffProfile::where('is_demo', true)->get();
        $files = [];

        foreach ($appeals as $appeal) {
            $files[] = $appeal->memo_path;
            $files = array_merge($files, $appeal->documents()->pluck('stored_path')->all(), $appeal->approvals()->pluck('signature_snapshot_path')->all());
        }

        $files = array_merge($files, $profiles->pluck('signature_path')->all());

        DB::transaction(function () use ($appeals, $profiles) {
            $ids = $appeals->pluck('id')->all();
            // history rows are never deleted by code: they go with their appeal / profile through the foreign keys' ON DELETE CASCADE
            DB::table('candidacy_appeal_approvals')->whereIn('candidacy_appeal_id', $ids)->delete();
            DB::table('candidacy_appeal_documents')->whereIn('candidacy_appeal_id', $ids)->delete();
            DB::table('candidacy_appeal_publications')->whereIn('candidacy_appeal_id', $ids)->delete();
            DB::table('candidacy_appeals')->whereIn('id', $ids)->delete();
            DB::table('candidacy_staff_assignments')->whereIn('staff_profile_id', $profiles->pluck('id'))->delete();
            DB::table('candidacy_staff_profiles')->whereIn('id', $profiles->pluck('id'))->delete();
            CandidacyDepartment::where('is_demo', true)->delete();
            DB::table('candidacy_demo_student_statuses')->delete();                                      // the demo overlay: all of it is demo data
            DB::table('candidacy_reminder_log')->whereNotNull('demo_profile_id')->delete();            // reminder rows made for demo students
        });

        foreach (array_filter($files) as $file) {
            $disk->delete($file);
        }

        foreach ($appeals as $appeal) {
            $disk->deleteDirectory('candidacy/appeals/'.$appeal->id);
        }

        $this->info("Removed {$appeals->count()} demo appeal(s) and {$profiles->count()} demo staff profile(s).");

        return self::SUCCESS;
    }
}
