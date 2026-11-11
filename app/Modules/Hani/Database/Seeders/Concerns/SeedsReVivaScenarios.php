<?php

namespace App\Modules\Hani\Database\Seeders\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\ReVivaExaminerAccounts;
use App\Modules\Hani\Support\ReVivaLetters;
use App\Modules\Hani\Support\ReVivaLogging;
use App\Modules\Hani\Support\ReVivaProcess;
use App\Modules\Hani\Support\WorkingDays;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * HaniDemoSeeder, re-viva part: one fictional student at each point of the
 * process, every step through the real services at the moment it would have
 * happened (at()). Rebuilt on every run. Examiners who get an account use the
 * shared demo password. No email is sent.
 */
trait SeedsReVivaScenarios
{
    /** A 1x1 transparent PNG: the seeded approvals' placeholder signature. */
    protected const DEMO_SIGNATURE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** Letter (a to l, not g), name. */
    protected const REVIVA_DEMO = [
        ['a', 'Adlina Rv-Eligible'], ['b', 'Bakri Rv-Submitted'], ['c', 'Camelia Rv-Returned'], ['d', 'Danish Rv-Pack'],
        ['e', 'Elina Rv-Dean'], ['f', 'Farid Rv-Ready'], ['h', 'Hazwani Rv-Reminder'], ['i', 'Irfan Rv-Diverging'],
        ['j', 'Jasmin Rv-Meeting'], ['k', 'Khairul Rv-Passed'], ['l', 'Lina Rv-Dismissed'],
    ];

    /** Used by the vivas in part 2. */
    protected function reVivaTimeline(): array
    {
        $s09Sent = $this->workingDaysAgo(5)->setTime(10, 0);
        $s09Logged = $s09Sent->copy()->subDays(7)->setTime(11, 0);
        $s10Outcome = today()->subDays(3)->setTime(15, 0);
        $s10Logged = $s10Outcome->copy()->subDays(45)->setTime(11, 0);

        return [
            'DEMO-S09' => ['viva' => $s09Logged->copy()->subMonthsNoOverflow(6)->subDays(10)->setTime(14, 30), 'logged' => $s09Logged, 'sent' => $s09Sent],
            'DEMO-S10' => ['viva' => $s10Logged->copy()->subMonthsNoOverflow(6)->subDays(20)->setTime(10, 0), 'logged' => $s10Logged, 'sent' => $s10Logged->copy()->addDays(5)->setTime(10, 0), 'evaluated' => $s10Outcome->copy()->subDays(10)->setTime(9, 30), 'outcome' => $s10Outcome],
        ];
    }

    protected function reVivaScenarios(): void
    {
        $real = Notification::getFacadeRoot();
        Notification::fake();

        try {
            $s09 = $this->demoUsers['DEMO-S09'] ?? null;
            $s10 = $this->demoUsers['DEMO-S10'] ?? null;
            $students = collect($this->reVivaDemoStudents)->concat(array_filter([$s09, $s10]));
            Application::whereIn('student_id', $students->pluck('id'))->where('module_type', 're_viva')->get()->each->delete();

            try {
                DB::transaction(function () use ($s09, $s10) {
                    $this->walkthrough($s09, $s10);
                });
            } finally {
                $this->removeDemoSignature();
            }

            Artisan::call('re-viva:monitor');
        } finally {
            Notification::swap($real);
        }

        $this->command?->table(['Point', 'Student login'], [
            ['(a) eligible', 'reviva.a@example.com'], ['(b) submitted', 'reviva.b@example.com'], ['(c) returned, re-uploaded', 'reviva.c@example.com'],
            ['(d) pack prepared', 'reviva.d@example.com'], ['(e) with the Dean', 'reviva.e@example.com'], ['(f) ready to send', 'reviva.f@example.com'],
            ['(g) panel review, examiner with an account', ($s09?->email ?? '-').' / examiner ain.ismail@example.com'], ['(h) MSc, at the 10-working-day reminder', 'reviva.h@example.com'],
            ['(i) reports in, diverging', 'reviva.i@example.com'], ['(j) meeting scheduled', 'reviva.j@example.com'], ['(k) passed level 2 (final thesis opens in 1 month)', 'reviva.k@example.com'],
            ['(l) dismissed', 'reviva.l@example.com'], ['(m) level 4, round 2 open', $s10?->email ?? '-'],
        ]);
    }

    protected function walkthrough(?User $s09, ?User $s10): void
    {
        $by = collect($this->reVivaDemoStudents)->mapWithKeys(fn ($student, $k) => [self::REVIVA_DEMO[$k][0] => $student]);
        $process = app(ReVivaProcess::class);
        $senior = $this->reVivaSenior();

        if ($s09) {
            $t = $this->reVivaTimeline()['DEMO-S09'];
            $round = $this->at($t['logged'], fn () => $this->logRound($s09));
            $this->at($t['logged'], fn () => $this->throughDean($round));
            $this->at($t['sent'], fn () => $this->sendPack($round));
        }

        if ($s10) {
            $t = $this->reVivaTimeline()['DEMO-S10'];
            $round = $this->at($t['logged'], fn () => $this->logRound($s10));
            $this->at($t['logged'], fn () => $this->throughDean($round));
            $this->at($t['sent'], fn () => $this->sendPack($round));
            $this->at($t['evaluated'], function () use ($round, $process, $senior) {
                $this->recordBoth($round, 3, null, 4, true);
                $process->scheduleMeeting($round->application, $senior, ['meeting_kind' => ReVivaProcess::CONSOLIDATION, 'meeting_at' => now()->addDays(5)->setTime(10, 0), 'meeting_venue' => 'CGS Meeting Room']);
            });
            $this->at($t['outcome'], fn () => $process->recordMeetingResult($round->application->refresh(), $senior, 4, 'At the consolidation meeting the examiners agreed the candidate must defend the thesis orally.'));
        }

        if ($by->isEmpty()) {
            return;
        }

        // The walkthrough rounds borrow history panels: leave the pool as it was, as the history does.
        $pool = \App\Modules\Hani\Models\Examiner::query()->get(['id', 'assigned_until', 'last_examination_date'])
            ->mapWithKeys(fn ($e) => [$e->id => ['assigned_until' => $e->getRawOriginal('assigned_until'), 'last_examination_date' => $e->getRawOriginal('last_examination_date')]]);

        $this->logRound($by['b']);

        $c = $this->logRound($by['c']);
        $process->returnToStudent($c->application, $senior, [ReVivaLogging::DOC_THESIS], 'Status of Thesis is not the first page.');
        $process->resubmit($c, [ReVivaLogging::DOC_THESIS => $this->tempPdf(FictionalPdfs::document('Re-corrected thesis (fictional)', ['Status of Thesis']), 'thesis.pdf')]);

        $d = $this->logRound($by['d']);
        $process->accept($d->application, $senior);

        $e = $this->logRound($by['e']);
        $process->accept($e->application, $senior);
        $process->sendToDean($e->application, $senior);

        $this->throughDean($this->logRound($by['f']));

        $h = $this->at($this->workingDaysAgo(12)->setTime(9, 0), function () use ($by) {
            $round = $this->logRound($by['h']);
            $this->throughDean($round);

            return $round;
        });
        $this->at($this->workingDaysAgo(10)->setTime(9, 0), fn () => $this->sendPack($h));

        foreach ([['i', 2, null, 4, true], ['j', 3, null, 4, true], ['k', 2, null, 2, null], ['l', 4, false, 4, false]] as [$letter, $c1, $v1, $c2, $v2]) {
            $round = $this->logRound($by[$letter]);
            $this->throughDean($round);
            $this->sendPack($round);
            $this->recordBoth($round, $c1, $v1, $c2, $v2);

            match ($letter) {
                'j' => $process->scheduleMeeting($round->application, $senior, ['meeting_kind' => ReVivaProcess::CONSOLIDATION, 'meeting_at' => now()->addDays(7)->setTime(10, 0), 'meeting_venue' => 'CGS Meeting Room']),
                'k' => $this->finishRound($round, 2, null),
                'l' => $this->finishRound($round, 5, 'Both examiners found that the necessary corrections were not made.'),
                default => null,
            };
        }

        foreach ($pool as $id => $fields) {
            \App\Modules\Hani\Models\Examiner::whereKey($id)->update($fields);
        }
    }

    /** The re-viva runs as Puan Waheda (waheda.cgs@, hani.reviva.cgs_recipients), never another Senior Executive. */
    protected function reVivaSenior(): User
    {
        return \App\Modules\Hani\Support\ReVivaRecipients::seniorExec() ?? $this->desk(Role::SENIOR_EXEC_CGS, 'Puan Waheeda (demo)');
    }

    /** The student submits, with fictional PDFs. */
    protected function logRound(User $student): ReVivaDetail
    {
        $title = \App\Modules\Hani\Models\VivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id))->value('thesis_title') ?? 'Thesis';
        $files = [
            ReVivaLogging::DOC_CONFIRMATION => $this->tempPdf(FictionalPdfs::document('Confirmation of Correction (fictional)', ['Candidate: '.$student->name]), 'confirmation.pdf'),
            ReVivaLogging::DOC_SUMMARY => $this->tempPdf(FictionalPdfs::document('Summary of Corrections (fictional)', ['Candidate: '.$student->name, 'Comment 1: clarify the research gap. Action: rewrote section 1.2.']), 'summary.pdf'),
            ReVivaLogging::DOC_THESIS => $this->tempPdf(FictionalPdfs::thesis($title, 1600, 6, $student->name), 'thesis.pdf'),
        ];

        return app(ReVivaLogging::class)->log($student, $files)['detail'];
    }

    /** Dean ids given the placeholder signature by this run, removed at the end. */
    protected array $demoSignatures = [];

    /** The seeded approvals are signed with a placeholder; the live Dean uploads their own. */
    protected function demoSignature(User $dean): void
    {
        // A row whose image is gone (deleted by hand, or by another seed
        // run) is replaced, not trusted: the Dean cannot approve without it.
        if (\App\Modules\Hani\Models\ReVivaSignature::forUser($dean->id)?->dataUri()) {
            return;
        }
        \App\Modules\Hani\Models\ReVivaSignature::where('user_id', $dean->id)->delete();
        \Illuminate\Support\Facades\Storage::disk('local')->put($path = 'hani/reviva-signatures/demo-'.$dean->id.'.png', base64_decode(self::DEMO_SIGNATURE));
        \App\Modules\Hani\Models\ReVivaSignature::create(['user_id' => $dean->id, 'path' => $path]);
        $this->demoSignatures[] = $dean->id;
    }

    protected function removeDemoSignature(): void
    {
        foreach ($this->demoSignatures as $id) {
            \App\Modules\Hani\Models\ReVivaSignature::where('user_id', $id)->delete();
            \Illuminate\Support\Facades\Storage::disk('local')->delete('hani/reviva-signatures/demo-'.$id.'.png');
        }
        $this->demoSignatures = [];
    }

    protected function throughDean(ReVivaDetail $detail): void
    {
        $this->demoSignature($this->desk(Role::DEAN_PGR, 'Dean of PGR (demo)'));
        $process = app(ReVivaProcess::class);
        $senior = $this->reVivaSenior();
        $process->accept($detail->application->refresh(), $senior);
        $process->sendToDean($detail->application->refresh(), $senior);
        $process->deanApprove($detail->application->refresh(), $this->desk(Role::DEAN_PGR, 'Dean of PGR (demo)'));
    }

    protected function sendPack(ReVivaDetail $detail): void
    {
        app(ReVivaLetters::class)->send($detail->application->refresh(), $this->reVivaSenior());

        foreach ($detail->refresh()->currentMains() as $examiner) {
            ReVivaExaminerAccounts::find($examiner)?->forceFill(['password' => 'password', 'email_verified_at' => now()])->save();
        }
    }

    protected function recordBoth(ReVivaDetail $detail, int $internal, ?bool $internalViva, int $external, ?bool $externalViva): void
    {
        $process = app(ReVivaProcess::class);
        $senior = $this->reVivaSenior();

        foreach ([['internal_main_id', $internal, $internalViva], ['external_main_id', $external, $externalViva]] as [$seat, $category, $viva]) {
            $process->recordEvaluation($detail->refresh(), $seat, ['category' => $category, 'viva_again' => $viva],
                $this->tempPdf(FictionalPdfs::document('Thesis Re-evaluation Report UTP/CGS/024 (fictional)', ['Category '.$category]), 'utp-cgs-024.pdf'),
                $this->tempPdf(FictionalPdfs::document('Signed letter (fictional)', ['Signed']), 'signed-letter.pdf'), $senior, false);
        }

        $process->startReview($detail->application->refresh(), $senior);
    }

    protected function finishRound(ReVivaDetail $detail, int $level, ?string $remarks): void
    {
        app(ReVivaProcess::class)->decideWithoutMeeting($detail->application->refresh(), $this->reVivaSenior(), $level, $remarks);
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
