<?php

namespace App\Modules\Hani\Database\Seeders\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Jobs\RunSoftboundChecks;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Models\VivaSoftboundCheck;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\Departments;
use App\Modules\Hani\Support\ExaminerProgress;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Support\Similarity\UploadedReportSource;
use App\Modules\Hani\Support\VivaChecklist;
use App\Modules\Hani\Support\VivaProgress;
use App\Modules\Hani\Support\VivaResult;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HaniDemoSeeder, part 2: the demo supervisors and students from the
 * dataset, each at the stage the dataset gives them.
 *
 * Every chain moves through the real WorkflowEngine (submit / decide), with
 * the real approvers: the candidate's own department's Academic Executive
 * from the shared test accounts (a demo one is created only where none
 * exists), and the CGS desks. Only Hani's own tables are written directly.
 *
 * Accounts: example.com, password "password", like the test accounts.
 * Every run rebuilds the demo students' viva, nomination and softbound
 * records from scratch so their dates are relative to today. The shared
 * test accounts and every other module's records are never touched.
 */
trait SeedsVivaScenarios
{
    /**
     * The four seats for each nominated student, by examiner email: internal
     * main, internal backup, external main, external backup. Chosen so that:
     *   - S03 (Chemical) and S04 (Civil) both name Firdaus Kamarudin: a
     *     cross-department clash at the Senior Executive's compile stage
     *   - S04 names "Prof Dr Hassan bin Omar" and S10 "Hassan Omar, PhD": the
     *     fuzzy duplicate flag
     *   - no conflict-of-interest examiner sits on a released panel, and no
     *     main examiner is on two released panels
     *   - Farah Liyana Rosli and Melissa Teoh stay Available for S01's
     *     live walkthrough
     */
    protected const PANELS = [
        'DEMO-S03' => ['lim.weikeat@example.com', 'arif.zainal@example.com', 'firdaus.k@example.org', 't.nakamura@example.org'],
        'DEMO-S04' => ['nadia.salleh@example.com', 'hidayah.musa@example.com', 'hassan.omar@example.org', 'firdaus.k@example.org'],
        'DEMO-S05' => ['haslinda.yusof@example.com', 'chong.meiling@example.com', 'rosnah.ahmad@example.org', 'norhayati.zakaria@example.org'],
        'DEMO-S06' => ['faizal.ali@example.com', 'jessica.wong@example.com', 's.whitmore@example.org', 'lee.chunhao@example.org'],
        'DEMO-S07' => ['suresh.pillai@example.com', 'nurul.jamal@example.com', 'wong.karlok@example.org', 'ikhwan.saad@example.org'],
        'DEMO-S08' => ['kevin.tan@example.com', 'syafiqah.hamzah@example.com', 'elizabeth.chan@example.org', 't.nakamura@example.org'],
        'DEMO-S09' => ['ain.ismail@example.com', 'vincent.lau@example.com', 'ikhwan.saad@example.org', 's.whitmore@example.org'],
        'DEMO-S10' => ['noraini.kassim@example.com', 'arif.zainal@example.com', 'h.omar@example.org', 't.nakamura@example.org'],
    ];

    /** Nomination stage each student's chain is taken to; 'released' runs it to the end. */
    protected const NOMINATION_STAGE = [
        'DEMO-S03' => 'cgs_compile',
        'DEMO-S04' => 'senior_director',
        'DEMO-S05' => 'released', 'DEMO-S06' => 'released', 'DEMO-S07' => 'released',
        'DEMO-S08' => 'released', 'DEMO-S09' => 'released', 'DEMO-S10' => 'released',
    ];

    /** @var array<string, User> demo users by dataset key */
    protected array $demoUsers = [];

    // ---- People -----------------------------------------------------------

    /** The demo supervisors and students (example.com, password "password"). */
    protected function people(): void
    {
        $departments = app(Departments::class);
        $department = fn (string $name) => $departments->resolve($name)['name'] ?? $name;

        foreach ($this->dataset()['supervisors'] ?? [] as $row) {
            $this->demoUsers[$row['key']] = User::updateOrCreate(['email' => $row['email']], [
                'name' => $row['name'],
                'password' => 'password',
                'role' => Role::SUPERVISOR,
                'department' => $department($row['department']),
            ]);
        }

        foreach ($this->dataset()['students'] ?? [] as $row) {
            $this->demoUsers[$row['key']] = User::updateOrCreate(['email' => $row['email']], [
                'name' => $row['name'],
                'password' => 'password',
                'role' => Role::STUDENT,
                'matric_no' => $row['matric'],
                'programme' => $row['programme'],
                'department' => $department($row['department']),
                'supervisor_id' => $this->demoUsers[$row['supervisor']]->id ?? null,
            ]);
        }

        // The Dean of PGR's demo account carries his name, so the re-viva
        // letters the demo approves print the right approver. The shared
        // DatabaseSeeder still seeds the old name (SHARED_CHANGES.md item 12).
        $deanName = (string) config('hani.reviva.signatory.name');
        if (User::where('email', 'dean@utp.edu.my')->where('role', Role::DEAN_PGR)->update(['name' => $deanName])) {
            $this->command?->info("  Dean of PGR demo account (dean@utp.edu.my) renamed to {$deanName}.");
        }

        $this->command?->info(sprintf('  Demo people: %d supervisors, %d students (password "password").',
            count($this->dataset()['supervisors'] ?? []), count($this->dataset()['students'] ?? [])));
    }

    // ---- Scenarios ----------------------------------------------------------

    protected function vivaScenarios(): void
    {
        $this->resetDemoRecords();

        foreach ($this->dataset()['students'] ?? [] as $row) {
            $method = 'scenario'.Str::studly(strtolower(str_replace('DEMO-', '', $row['key'])));

            if (method_exists($this, $method)) {
                DB::transaction(fn () => $this->{$method}($row, $this->demoUsers[$row['key']]));
            }
        }

        $this->command?->info('  Viva scenarios: S01 not started, S02 notice waiting, S03/S04 nomination stages, S05 softbound returned, '
            .'S06 ready with diverging reports, S07 viva in 7 days, S08 result 2, S09 and S10 result 4.');
    }

    /**
     * Remove the demo students' own re-viva, viva, softbound and nomination records,
     * and every tie-up on the demo pool, so the scenarios are rebuilt with
     * today's dates. Cascades take the detail rows with them.
     */
    protected function resetDemoRecords(): void
    {
        $ids = collect($this->demoUsers)->filter(fn (User $u) => $u->role === Role::STUDENT)->pluck('id');

        Application::whereIn('student_id', $ids)
            ->whereIn('module_type', ['re_viva', VivaProgress::NOTICE, VivaProgress::SOFTBOUND, VivaProgress::NOMINATION])
            ->get()
            ->each->delete();
        VivaEligibility::whereIn('student_id', $ids)->delete();

        // The pool as the dataset describes it, moved to today. The import
        // never moves an examination date backwards (right for a real
        // upload), so an evaluation completed during a demo would otherwise
        // stay; and no tie-up survives from the last run.
        foreach ($this->dataset()['examiners'] ?? [] as $row) {
            if (filled($row['email'] ?? null)) {
                Examiner::whereRaw('LOWER(email) = ?', [mb_strtolower($row['email'])])->update([
                    'assigned_until' => null,
                    'last_examination_date' => $this->shift(($row['last_examination_date'] ?? '') ?: null),
                ]);
            }
        }
    }

    protected function scenarioS02(array $row, User $student): void
    {
        $this->notice($row, $student, acknowledge: false, filedDaysAgo: 2);
    }

    protected function scenarioS03(array $row, User $student): void
    {
        $this->notice($row, $student, acknowledge: true, filedDaysAgo: 40);
        $this->nomination($row, $student);
    }

    protected function scenarioS04(array $row, User $student): void
    {
        $this->scenarioS03($row, $student);
    }

    protected function scenarioS05(array $row, User $student): void
    {
        $notice = $this->notice($row, $student, acknowledge: true, filedDaysAgo: 100);
        $this->nomination($row, $student);
        $this->softbound($row, $student, $notice, 'reject',
            'Turnitin report is older than 30 days; please upload a current report.', receiptDaysAgo: 45);
    }

    protected function scenarioS06(array $row, User $student): void
    {
        $notice = $this->notice($row, $student, acknowledge: true, filedDaysAgo: 150);
        $panel = $this->nomination($row, $student);
        $this->softbound($row, $student, $notice, 'approve');
        $this->examinerReports($notice, $panel, ['internal_main_id' => 2, 'external_main_id' => 4], reportsDaysAgo: [10, 5]);
    }

    protected function scenarioS07(array $row, User $student): void
    {
        $notice = $this->notice($row, $student, acknowledge: true, filedDaysAgo: 150);
        $panel = $this->nomination($row, $student);
        $this->softbound($row, $student, $notice, 'approve');
        $this->examinerReports($notice, $panel, ['internal_main_id' => 2, 'external_main_id' => 2], reportsDaysAgo: [12, 9]);
        $this->schedule($notice, today()->addDays(7)->setTime(10, 0), 'Seminar Room 2, Chancellor Complex', 'Prof Dr Mahmud bin Idris', 'mahmud.idris@example.com');
    }

    protected function scenarioS08(array $row, User $student): void
    {
        $notice = $this->notice($row, $student, acknowledge: true, filedDaysAgo: 180);
        $panel = $this->nomination($row, $student);
        $this->softbound($row, $student, $notice, 'approve');
        $this->examinerReports($notice, $panel, ['internal_main_id' => 2, 'external_main_id' => 3], reportsDaysAgo: [30, 25]);
        $this->schedule($notice, today()->subDays(14)->setTime(10, 0), 'Seminar Room 1, Chancellor Complex', 'Prof Dr Mahmud bin Idris', 'mahmud.idris@example.com');
        $this->result($notice, 2);
    }

    /**
     * S09 and S10: category-4 vivas on the re-viva timeline (part 3,
     * SeedsReVivaScenarios), held long enough ago for their windows to be
     * open. Reports, the viva and the result happen at their own moments.
     */
    protected function scenarioS09(array $row, User $student): void
    {
        $t = $this->reVivaTimeline()['DEMO-S09'];
        $this->categoryFourViva($row, $student, $t['viva'], ['internal_main_id' => 4, 'external_main_id' => 4],
            'Online: meet.example.com/viva-s09', 'Prof Dr Rahimah binti Said', 'rahimah.said@example.com');
    }

    protected function scenarioS10(array $row, User $student): void
    {
        $t = $this->reVivaTimeline()['DEMO-S10'];
        $this->categoryFourViva($row, $student, $t['viva'], ['internal_main_id' => 3, 'external_main_id' => 4],
            'Seminar Room 2, Chancellor Complex', 'Prof Dr Zainal bin Ahmad', 'zainal.ahmad@example.com');
    }

    protected function categoryFourViva(array $row, User $student, Carbon $vivaAt, array $categories, string $venue, string $chair, string $chairEmail): void
    {
        $daysAgo = (int) $vivaAt->copy()->startOfDay()->diffInDays(today());
        $notice = $this->notice($row, $student, acknowledge: true, filedDaysAgo: $daysAgo + 150);
        $panel = $this->nomination($row, $student);
        $this->softbound($row, $student, $notice, 'approve');
        $this->at($vivaAt->copy()->subDays(7), fn () => $this->examinerReports($notice, $panel, $categories, reportsDaysAgo: [9, 6]));
        $this->schedule($notice, $vivaAt, $venue, $chair, $chairEmail);
        $this->at($vivaAt->copy()->addDay()->setTime(10, 0), fn () => $this->result($notice, 4));
    }

    // ---- Building blocks ------------------------------------------------------

    protected function notice(array $row, User $student, bool $acknowledge, int $filedDaysAgo): Application
    {
        $engine = app(WorkflowEngine::class);

        VivaEligibility::updateOrCreate(['student_id' => $student->id], array_fill_keys(array_keys(VivaChecklist::itemsFor($student)), true) + [
            'system_checks' => array_map(fn ($r) => $r->toArray(), VivaChecklist::systemChecks($student)),
            'system_checked_at' => now(),
        ]);

        $notice = Application::create(['student_id' => $student->id, 'module_type' => VivaProgress::NOTICE, 'status' => Application::STATUS_DRAFT]);
        VivaDetail::create([
            'application_id' => $notice->id,
            'programme_level' => VivaChecklist::levelFor($student),
            'thesis_title' => $row['thesis_title'],
            'abstract' => $row['abstract'],
            'keywords' => VivaDetail::parseKeywords($row['keywords']),
            'expected_softbound_date' => today()->subDays($filedDaysAgo)->addMonthsNoOverflow(3)->addDays(7),
        ]);
        // Fictional documents only: a paper whose author list names the
        // student, with a made-up DOI and ISSN, and a Research Methodology result.
        app(DocumentStore::class)->storeGenerated($notice,
            FictionalPdfs::document('Journal of Example Studies (fictional)', [
                $row['thesis_title'],
                'Authors: '.$student->name.', '.($student->supervisor?->name ?? 'A. Supervisor'),
                'Journal of Example Studies, Vol. 12, 2026. ISSN 1234-5679',
                'DOI: 10.5555/example.'.Str::slug($student->matric_no ?: (string) $student->id),
                'This manuscript has been accepted for publication.',
            ]),
            'publication-paper-1.pdf', VivaDetail::DOC_PAPER);
        app(DocumentStore::class)->storeGenerated($notice,
            FictionalPdfs::document('Research Methodology result (fictional)', ['Student: '.$student->name, 'Research Methodology: Pass']),
            'research-methodology-result.pdf', VivaDetail::DOC_METHODOLOGY);

        $engine->submit($notice);
        $this->backdate($notice, $filedDaysAgo);
        \App\Modules\Hani\Jobs\RunNoticeChecks::dispatchSync($notice->id);

        // The supervisor endorses first (UAT 11 Nov 2026), a day after filing,
        // so every seeded notice is past that stage: waiting for the AE or verified.
        if ($notice->current_stage === \App\Modules\Hani\Workflows\VivaVoceWorkflow::SUPERVISOR_STAGE) {
            $this->at(now()->subDays(max(0, $filedDaysAgo - 1)), fn () => $engine->decide($notice, $student->supervisor, 'approve', 'Endorsed.'));
        }

        if ($acknowledge) {
            // Acknowledged two days after filing, not today: approval_history
            // feeds Process Analytics, and a 150-day "wait" would be invented.
            $ae = $this->academicExecFor($student);
            $this->at(now()->subDays(max(0, $filedDaysAgo - 2)), fn () => $engine->decide($notice, $ae, 'approve'));
            VivaEligibility::where('student_id', $student->id)->update(['verified_by_id' => $ae->id, 'verified_at' => now()->subDays(max(0, $filedDaysAgo - 2))]);
            VivaDetail::where('application_id', $notice->id)->update([
                'ae_item_checks' => json_encode(array_fill_keys(array_keys(VivaChecklist::itemsFor($student)), 'verified')),
                'ae_checked_by_id' => $ae->id, 'ae_checked_at' => now()->subDays(max(0, $filedDaysAgo - 2)),
            ]);
        }

        return $notice;
    }

    protected function nomination(array $row, User $student): ?ExaminerNomination
    {
        $emails = self::PANELS[$row['key']] ?? null;
        if (! $emails) {
            return null;
        }

        $engine = app(WorkflowEngine::class);
        $examiner = fn (string $email) => Examiner::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->value('id');

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->supervisor_id,
            'module_type' => VivaProgress::NOMINATION,
            'status' => Application::STATUS_DRAFT,
        ]);
        $nomination = ExaminerNomination::create([
            'application_id' => $application->id,
            'internal_main_id' => $examiner($emails[0]),
            'internal_backup_id' => $examiner($emails[1]),
            'external_main_id' => $examiner($emails[2]),
            'external_backup_id' => $examiner($emails[3]),
            'thesis_title' => $row['thesis_title'],
        ]);
        $engine->submit($application);
        $this->backdate($application, 30);

        $target = self::NOMINATION_STAGE[$row['key']] ?? 'released';
        $desks = [
            'academic_exec' => fn () => $this->academicExecFor($student),
            'cgs_compile' => fn () => $this->desk(Role::SENIOR_EXEC_CGS, 'Puan Waheeda (demo)'),
            'senior_director' => fn () => $this->desk(Role::SENIOR_DIRECTOR_CGS, 'Senior Director (demo)'),
            'dean' => fn () => $this->desk(Role::DEAN_PGR, 'Dean of PGR (demo)'),
            'cgs_final' => fn () => $this->desk(Role::SENIOR_EXEC_CGS, 'Puan Waheeda (demo)'),
            'cgs_release' => fn () => $this->desk(Role::SENIOR_EXEC_CGS, 'Puan Waheeda (demo)'),
        ];

        // Filed 30 days ago; each stage decided four days after the last
        // (Finalise and release the same afternoon), so the stage times in
        // Process Analytics are believable. A list left waiting at its target
        // stage has waited since its last decision, as the SLA reminders see it.
        $step = 0;
        while ($application->status === Application::STATUS_PENDING && $application->current_stage !== $target) {
            $stage = $application->current_stage;
            $step += $stage === 'cgs_release' ? 0 : 1;
            $this->at(now()->subDays(30 - 4 * $step)->setTime(10, 0)->addMinutes($stage === 'cgs_release' ? 30 : 0),
                fn () => $stage === 'academic_exec'
                    ? $this->dacEndorse($application, $desks[$stage]())
                    : $engine->decide($application, $desks[$stage](), 'approve'));
            $application->refresh();
        }

        if ($application->status === Application::STATUS_APPROVED) {
            app(PanelSeats::class)->release($nomination->refresh());
        }

        return $nomination;
    }

    /** A softbound attempt with fictional PDFs, read and checked like a real one, then decided. */
    protected function softbound(array $row, User $student, Application $notice, string $decision, ?string $remarks = null, ?int $receiptDaysAgo = null): Application
    {
        $engine = app(WorkflowEngine::class);
        $documents = app(DocumentStore::class);
        $level = VivaChecklist::levelFor($student);
        $words = $level === VivaChecklist::PHD ? 2400 : 1600;

        $report = FictionalPdfs::turnitinReport($row['thesis_title'], 11, [8, 4, 2], receipt: $receiptDaysAgo === null ? null : [
            'date' => today()->subDays($receiptDaysAgo)->format('d-M-Y').' 10:15AM (UTC+0800)', 'id' => '24'.substr(md5($row['key']), 0, 8),
        ]);
        $reading = app(UploadedReportSource::class)->read($report);

        $attempt = Application::create(['student_id' => $student->id, 'module_type' => VivaProgress::SOFTBOUND, 'status' => Application::STATUS_DRAFT]);
        VivaSoftboundDetail::create([
            'application_id' => $attempt->id,
            'viva_application_id' => $notice->id,
            'field_of_study' => $row['keywords'] ? Str::title(VivaDetail::parseKeywords($row['keywords'])[0]) : 'Research',
            'contact_number' => '012-345 6789',
            'personal_email' => Str::before($student->email, '@').'.personal@example.com',
            'session' => now()->format('F'),
            'year' => now()->year,
            'thesis_title' => $row['thesis_title'],
            'similarity_percent' => 11,
            'similarity_source' => $reading->ok ? 'extracted' : 'self_declared',
            'similarity_extracted' => $reading->ok ? $reading->index : null,
            'word_count' => $words,
            'declaration_accepted' => true,
        ]);

        $documents->storeGenerated($attempt, FictionalPdfs::document('Thesis Format Confirmation Form (fictional)', ['Candidate: '.$student->name]), 'format-confirmation.pdf', 'Thesis Format Confirmation Form');
        $documents->storeGenerated($attempt, FictionalPdfs::thesis($row['thesis_title'], $words, 4, $student->name), 'thesis.pdf', 'Softbound Thesis');
        $documents->storeGenerated($attempt, $report, 'turnitin-report.pdf', 'Turnitin Report');
        if ($level === VivaChecklist::PHD) {
            $documents->storeGenerated($attempt, FictionalPdfs::document('RCS Correction Confirmation Form (fictional)'), 'rcs-confirmation.pdf', 'RCS Correction Confirmation Form');
            $documents->storeGenerated($attempt, FictionalPdfs::document('RCS Correction Summary (fictional)'), 'rcs-summary.pdf', 'RCS Correction Summary');
        }

        VivaSoftboundCheck::create(['application_id' => $attempt->id, 'status' => VivaSoftboundCheck::QUEUED, 'similarity' => $reading->toArray()]);
        $engine->submit($attempt);
        app()->call([new RunSoftboundChecks($attempt->id), 'handle']);

        $engine->decide($attempt, $this->academicExecFor($student), $decision, $remarks);

        return $attempt;
    }

    /**
     * Both mains accepted, received the thesis and returned their reports,
     * with the given categories.
     *
     * @param  array<string, int>  $categories  seat => category
     * @param  array{0: int, 1: int}  $reportsDaysAgo
     */
    protected function examinerReports(Application $notice, ?ExaminerNomination $panel, array $categories, array $reportsDaysAgo): void
    {
        if (! $panel) {
            return;
        }

        $rows = app(ExaminerProgress::class)->sync($notice, $panel);
        $i = 0;

        foreach ($categories as $seat => $category) {
            $returned = $reportsDaysAgo[$i++] ?? 5;
            $rows->get($seat)?->update([
                'letter_sent_at' => today()->subDays($returned + 45),
                'letter_source' => 'manual',
                'acknowledgement' => 'accepted',
                'acknowledged_at' => today()->subDays($returned + 40),
                'thesis_received_at' => today()->subDays($returned + 25),
                'report_received_at' => today()->subDays($returned),
                'category' => $category,
            ]);
        }
    }

    protected function schedule(Application $notice, Carbon $at, string $venue, string $chair, string $chairEmail): void
    {
        VivaDetail::where('application_id', $notice->id)->update([
            'viva_at' => $at,
            'venue' => $venue,
            'chair_name' => $chair,
            'chair_email' => $chairEmail,
            'scheduled_by_id' => $this->academicExecFor($notice->student)->id,
        ]);
    }

    /** The result, through the same service the AE's form uses. */
    protected function result(Application $notice, int $category): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hani-viva-report');
        file_put_contents($path, FictionalPdfs::document('Viva Voce Report (fictional)', [
            'Candidate: '.$notice->student->name,
            'The panel recommends result category '.$category.'.',
            'Chairman: as recorded in UResearch.',
        ]));

        try {
            app(VivaResult::class)->record($notice, $category, new UploadedFile($path, 'viva-report.pdf', 'application/pdf', null, true), $this->academicExecFor($notice->student));
        } finally {
            @unlink($path);
        }
    }

    // ---- Who decides ----------------------------------------------------------

    /** The candidate's own department's AE from the test accounts, or a demo one. */
    protected function academicExecFor(User $student): User
    {
        return User::where('role', Role::ACADEMIC_EXEC)->where('department', $student->department)->orderBy('id')->first()
            ?? User::create([
                'name' => 'Academic Executive (demo, '.$student->department.')',
                'email' => 'ae.demo.'.Str::slug((string) $student->department).'@example.com',
                'password' => 'password',
                'role' => Role::ACADEMIC_EXEC,
                'department' => $student->department,
            ]);
    }

    /** The CGS desk for a role from the test accounts, or a demo one. */
    /** Stage 1 as the AE now decides it: a DAC record (fictional reference), then the engine's approve. */
    protected function dacEndorse(Application $application, User $ae): void
    {
        $nomination = ExaminerNomination::where('application_id', $application->id)->first();
        \App\Modules\Hani\Models\ExaminerDacRecord::create([
            'nomination_id' => $nomination?->id, 'application_id' => $application->id,
            'outcome' => \App\Modules\Hani\Models\ExaminerDacRecord::ENDORSED, 'meeting_date' => now()->toDateString(),
            'reference' => 'DAC/DEMO/'.now()->format('Y/m'), 'recorded_by_id' => $ae->id,
        ]);
        app(WorkflowEngine::class)->decide($application, $ae, 'approve', 'Endorsed by the DAC on '.now()->format('j M Y').', ref DAC/DEMO/'.now()->format('Y/m').'.');
    }

    protected function desk(string $role, string $name): User
    {
        return User::where('role', $role)->orderBy('id')->first()
            ?? User::create(['name' => $name, 'email' => str_replace('_', '.', $role).'.demo@example.com', 'password' => 'password', 'role' => $role]);
    }

    /**
     * Give a demo application a believable filing date. Only submitted_at and
     * created_at, never status or current_stage (those stay the engine's).
     */
    protected function backdate(Application $application, int $daysAgo): void
    {
        Application::whereKey($application->id)->update([
            'submitted_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo),
        ]);
        $application->refresh();
    }
}
