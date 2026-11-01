<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Chloe\Support\StudentStatus;
use App\Modules\Chloe\Support\StudentStatusReader;
use Illuminate\Console\Command;

/** Read-only: how many students' Softbound answer comes from the shared source, from the demo overlay, from our own candidacy record, or from nowhere. */
class CheckCandidacyStatusSource extends Command
{
    protected $signature = 'candidacy:check-status-source';

    protected $description = 'Count the students whose Softbound / status answer resolves from shared, the demo overlay, the candidacy record or nothing';

    public function handle(): int
    {
        $count = [StudentStatus::SHARED => 0, StudentStatus::OVERLAY => 0, StudentStatus::RECORD => 0, StudentStatus::NONE => 0];
        $total = 0;
        $identities = StudyCandidacy::with('student')->get()->map(fn ($c) => $c->student ? StudentDirectory::fromUser($c->student) : null)->filter()
            ->concat(DemoStudentProfile::orderBy('id')->get()->map(fn ($p) => StudentDirectory::fromDemoProfile($p)));

        foreach ($identities as $identity) {
            $status = StudentStatusReader::for($identity);
            $source = collect([$status->sources['softbound'], $status->sources['completed'], $status->sources['inactive']])->first(fn ($s) => $s !== StudentStatus::NONE) ?? StudentStatus::NONE;
            $count[$source]++;
            $total++;
        }

        $this->line('status_source = '.config('candidacy.status_source').'; shared table '.(\App\Modules\Chloe\Support\SharedStatusAdapter::isConfigured() ? 'configured' : 'NOT configured yet'));
        $this->table(['Resolved from', 'Students'], [['shared', $count[StudentStatus::SHARED]], ['demo overlay (Demo data)', $count[StudentStatus::OVERLAY]], ['candidacy record', $count[StudentStatus::RECORD]], ['none (no record = not submitted)', $count[StudentStatus::NONE]]]);
        $this->info("{$total} students checked.");

        return self::SUCCESS;
    }
}
