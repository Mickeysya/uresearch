<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\DemoStudentProfile;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** DEMO ONLY. Writes the demo overlay (never a shared table) for one of our demo students, so the status reader and the reminders can be shown. */
class CandidacyDemoSoftbound extends Command
{
    protected $signature = 'candidacy:demo-softbound {studentID : the demo student\'s ID (matric number)}
        {--date= : Softbound submitted on this date (default: today)}
        {--completed : mark the student completed}
        {--inactive : mark the student inactive / deferred}
        {--remove : remove the overlay row of this student}';

    protected $description = 'DEMO ONLY: set a demo student\'s Softbound / completed / inactive status in the labelled demo overlay';

    public function handle(): int
    {
        $profile = DemoStudentProfile::where('matric_no', $this->argument('studentID'))->first();

        if (! $profile) {
            $this->error('That is not one of our demo students. The demo overlay never applies to real students.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            CandidacyDemoStudentStatus::where('demo_profile_id', $profile->id)->delete();
            $this->info("Overlay removed for {$profile->matric_no}.");

            return self::SUCCESS;
        }

        $onlyFlag = $this->option('completed') || $this->option('inactive');
        $row = CandidacyDemoStudentStatus::updateOrCreate(['demo_profile_id' => $profile->id], [
            'softbound_submitted_at' => $onlyFlag && ! $this->option('date') ? null : Carbon::parse($this->option('date') ?: 'today', 'Asia/Kuala_Lumpur')->toDateString(),
            'completed' => (bool) $this->option('completed'),
            'inactive' => (bool) $this->option('inactive'),
        ]);

        $this->info("Demo overlay for {$profile->matric_no} ({$profile->name}): Softbound ".($row->softbound_submitted_at?->toDateString() ?? 'no').', completed '.($row->completed ? 'yes' : 'no').', inactive '.($row->inactive ? 'yes' : 'no').'. Demo data.');

        return self::SUCCESS;
    }
}
