<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use Illuminate\Console\Command;

/**
 * The attendance risk check and automatic early warnings on their own,
 * without pulling new data -- what every `attendance:sync` already ends
 * with. For the first run after deploying the projection (so CGS's at-risk
 * list is populated before the next nightly sync), and after CGS changes
 * nureen.attendance.semester_start or semester_periods.
 */
class CheckAttendanceRisk extends Command
{
    protected $signature = 'attendance:check-risk';

    protected $description = 'Re-run the attendance risk check (rule + end-of-semester projection) and send any due early warnings';

    public function handle(AttendanceEarlyWarning $earlyWarning): int
    {
        $result = $earlyWarning->run();

        $this->info("Checked {$result['evaluated']} students. {$result['newly_at_risk']} newly at risk, {$result['alerted']} emailed.");

        return self::SUCCESS;
    }
}
