<?php

namespace App\Modules\Hani\Database\Seeders\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaEvaluation;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\ReVivaLetters;
use App\Modules\Hani\Support\ReVivaLogging;
use App\Modules\Hani\Support\ReVivaOutcome;
use App\Modules\Hani\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HaniDemoSeeder, part 3: re-viva rounds for the two category-4 students.
 *
 *   S09  round 1 Under Panel Review, letters sent 22 working days ago, so
 *        `re-viva:monitor` has already reminded the AE to chase the panel
 *   S10  round 1 finished: internal 3, external 4 + viva YES, consolidated
 *        to level 4 three days ago; ready for the Senior Executive to log
 *        round 2 (student pre-selected from the notification)
 *
 * Every step runs through the real services (ReVivaLogging, ReVivaLetters,
 * WorkflowEngine, ReVivaOutcome) at the moment it would have happened: the
 * clock is set to that moment (at()), so letters, holds, deadlines and
 * timestamps agree with each other, all relative to the run day. The two
 * re-evaluations are written straight to Hani's own tables, as the AE's
 * form would leave them.
 */
trait SeedsReVivaScenarios
{
    /** The re-viva timeline, relative to today. The vivas in part 2 are built from it. */
    protected function reVivaTimeline(): array
    {
        $s09Sent = $this->workingDaysAgo(22)->setTime(10, 0);
        $s09Logged = $s09Sent->copy()->subDays(3)->setTime(11, 0);

        $s10Outcome = today()->subDays(3)->setTime(15, 0);
        $s10Logged = $s10Outcome->copy()->subDays(45)->setTime(11, 0);

        return [
            'DEMO-S09' => [
                'viva' => $s09Logged->copy()->subMonthsNoOverflow(6)->subDays(10)->setTime(14, 30),
                'logged' => $s09Logged,
                'sent' => $s09Sent,
            ],
            'DEMO-S10' => [
                'viva' => $s10Logged->copy()->subMonthsNoOverflow(6)->subDays(20)->setTime(10, 0),
                'logged' => $s10Logged,
                'sent' => $s10Logged->copy()->addDays(2)->setTime(10, 0),
                'reviewed' => $s10Logged->copy()->addDays(30)->setTime(9, 30),
                'evaluated' => $s10Outcome->copy()->subDays(7)->setTime(9, 30),
                'consolidated' => $s10Outcome->copy()->subDays(1)->setTime(16, 0),
                'outcome' => $s10Outcome,
            ],
        ];
    }

    protected function reVivaScenarios(): void
    {
        $timeline = $this->reVivaTimeline();

        foreach (['DEMO-S09', 'DEMO-S10'] as $key) {
            $student = $this->demoUsers[$key] ?? null;
            if ($student) {
                DB::transaction(fn () => $this->{'reViva'.Str::studly(strtolower(str_replace('DEMO-', '', $key)))}($student, $timeline[$key]));
            }
        }

        // The checks CGS would have had by today: S09's 20-working-day chase.
        Artisan::call('re-viva:monitor');

        $this->command?->info('  Re-viva: S09 under panel review for 22 working days (AE reminded), S10 round 1 at level 4, round 2 to log.');
    }

    protected function reVivaS09(User $student, array $t): void
    {
        $detail = $this->at($t['logged'], fn () => $this->logRound($student));
        $this->at($t['sent'], fn () => app(ReVivaLetters::class)->send($detail->application, $this->academicExecFor($student)));
    }

    protected function reVivaS10(User $student, array $t): void
    {
        $engine = app(WorkflowEngine::class);
        $ae = $this->academicExecFor($student);

        $detail = $this->at($t['logged'], fn () => $this->logRound($student));
        $this->at($t['sent'], fn () => app(ReVivaLetters::class)->send($detail->application, $ae));
        $this->at($t['reviewed'], fn () => $engine->decide($detail->application->refresh(), $ae, 'approve', 'Both examiners confirmed receipt of the corrected thesis.'));

        $this->at($t['evaluated'], function () use ($detail, $ae, $engine) {
            $mains = $detail->currentMains();
            foreach ([['internal_main_id', 3, null], ['external_main_id', 4, true]] as [$seat, $category, $vivaAgain]) {
                if (! isset($mains[$seat])) {
                    continue;
                }
                $path = 'hani/reviva-reports/'.Str::random(40).'.pdf';
                Storage::disk('local')->put($path, FictionalPdfs::document('Thesis Re-evaluation Report UTP/CGS/024 (fictional)', [
                    'Examiner: '.$mains[$seat]->name,
                    'Recommendation: '.$category.($vivaAgain !== null ? ', required to attend the viva again: YES' : ''),
                ]));
                $report = ReVivaStaffDocument::create([
                    're_viva_detail_id' => $detail->id, 'kind' => ReVivaStaffDocument::RE_EVALUATION,
                    'seat' => $seat, 'examiner_id' => $mains[$seat]->id,
                    'path' => $path, 'original_name' => 'utp-cgs-024-'.Str::slug($mains[$seat]->name).'.pdf', 'created_by_id' => $ae->id,
                ]);
                ReVivaEvaluation::create([
                    're_viva_detail_id' => $detail->id, 'seat' => $seat, 'examiner_id' => $mains[$seat]->id,
                    'category' => $category, 'viva_again' => $vivaAgain,
                    'report_document_id' => $report->id, 'recorded_by_id' => $ae->id,
                ]);
            }
            $engine->decide($detail->application->refresh(), $ae, 'approve');
        });

        $this->at($t['consolidated'], fn () => $engine->decide($detail->application->refresh(), $ae, 'approve', 'Consolidation meeting held with both examiners.'));
        $this->at($t['outcome'], fn () => app(ReVivaOutcome::class)->record(
            $detail->refresh(), ReVivaDetail::OUTCOME_LOOP_BACK,
            'The examiners differed (major corrections against a fail with the viva again). At consolidation they agreed the candidate must defend the thesis orally.',
            $ae,
        ));
    }

    /** The Senior Executive logs a round with fictional PDFs, through the same service as the form. */
    protected function logRound(User $student): ReVivaDetail
    {
        $title = \App\Modules\Hani\Models\VivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id))->value('thesis_title') ?? 'Thesis';
        $thesis = $this->tempPdf(FictionalPdfs::thesis($title, 1600, 6, $student->name), 'corrected-thesis.pdf');
        $summary = $this->tempPdf(FictionalPdfs::document('Summary of Corrections (fictional)', [
            'Candidate: '.$student->name,
            'Examiner 1, comment 1: clarify the research gap. Action: rewrote section 1.2. Pages 3-4.',
            'Examiner 2, comment 1: justify the sample size. Action: added section 3.4. Page 5.',
            'Endorsed by the supervisor.',
        ]), 'summary-of-corrections.pdf');

        try {
            return app(ReVivaLogging::class)->log($student, $thesis, $summary, null, $this->desk(Role::SENIOR_EXEC_CGS, 'Puan Waheeda (demo)'))['detail'];
        } finally {
            @unlink($thesis->getPathname());
            @unlink($summary->getPathname());
        }
    }

    protected function tempPdf(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hani-reviva');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /** Run $fn as if it were $when, then put the clock back. */
    protected function at(Carbon $when, callable $fn): mixed
    {
        $real = Carbon::getTestNow();
        Carbon::setTestNow($when);

        try {
            return $fn();
        } finally {
            Carbon::setTestNow($real);
        }
    }

    protected function workingDaysAgo(int $days): Carbon
    {
        $day = today();
        while ($days > 0) {
            $day->subDay();
            if (WorkingDays::isWorkingDay($day)) {
                $days--;
            }
        }

        return $day;
    }
}
