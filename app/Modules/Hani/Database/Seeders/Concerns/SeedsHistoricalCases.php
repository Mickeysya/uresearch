<?php

namespace App\Modules\Hani\Database\Seeders\Concerns;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\Departments;
use App\Modules\Hani\Support\ExaminerProgress;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Support\VivaChecklist;
use App\Modules\Hani\Support\VivaProgress;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * HaniDemoSeeder, part 4 (Prompt 4c): twenty-four FICTIONAL completed cases
 * (H01-H24) over the past year, so Process Analytics has something to say.
 *
 * Each case is a whole journey: notice filed and acknowledged, the
 * six-stage nomination chain (some lists sent back by the Senior Director
 * or the Dean), the softbound check (some returned once), both examiner
 * reports, the viva, the result and corrections accepted. Four are category
 * 4 and go on through a re-viva round to an outcome. Every decision runs
 * through the real WorkflowEngine and Hani's services with the clock set to
 * the moment it happened (at()), so approval_history carries the durations
 * honestly; status and current_stage are never written here.
 *
 * Durations are varied but fixed: each case draws from its own seeded
 * random sequence, and the dates are anchored to the run day, so every run
 * gives the same picture relative to today. Completions are spread from 5
 * to about 330 days ago.
 *
 * What it leaves alone:
 *   - No email: notifications are faked for this step only, then restored.
 *   - The examiner pool's state: assigned_until and last_examination_date
 *     are put back afterwards, so On Gap / Assigned / Available are exactly
 *     what parts 1-3 left. The finished panels stay in the seat history (the
 *     utilisation chart reads it) and count as last year's examinations in
 *     the suggestions' load tie-breaker, as real history would.
 *   - Files: only what the services generate themselves (the viva report,
 *     re-viva letters and UTP/CGS/024 forms). Notices and softbounds carry
 *     their records, not uploads.
 */
trait SeedsHistoricalCases
{
    protected const HISTORY_NAMES = [
        'Adam Haikal bin Rosli', 'Balqis Humaira binti Azmi', 'Chua Wei Ling', 'Darshan a/l Kumar',
        'Elya Maisarah binti Hamdan', 'Farhan Iqbal bin Zakaria', 'Goh Shu Fen', 'Hafiy Danial bin Rahim',
        'Irdina Safiya binti Mokhtar', 'Jeevan a/l Ramasamy', 'Khairul Anwar bin Salleh', 'Lim Jia Wen',
        'Marissa Qistina binti Yusof', 'Naufal Hakimi bin Azlan', 'Ong Kai Xiang', 'Puteri Alya binti Kamal',
        'Qayyum Rizqi bin Hassan', 'Rachel Tan Hui Min', 'Syazwan Amri bin Daud', 'Thivya a/p Selvam',
        'Umairah Husna binti Ismail', 'Vernon Lee Chee Keong', 'Wardah Insyirah binti Osman', 'Yusri Fikri bin Jamil',
    ];

    protected const HISTORY_TITLES = [
        'Graph neural networks for fault detection in offshore compressors',
        'Reservoir characterisation of fractured carbonates using machine learning',
        'Membrane separation of carbon dioxide from natural gas at high pressure',
        'Durability of geopolymer concrete in marine environments',
        'Explainable anomaly detection for industrial time series',
        'Corrosion inhibition of carbon steel by plant-derived extracts',
        'Partial discharge monitoring in medium-voltage cable joints',
        'Sustainable supply chain practices in Malaysian manufacturing firms',
        'Heat transfer enhancement with hybrid nanofluids in compact exchangers',
        'Photocatalytic degradation of produced water contaminants',
        'Federated learning for privacy-preserving healthcare analytics',
        'Enhanced oil recovery by low-salinity water flooding',
        'Kinetics of biomass gasification in fluidised beds',
        'Seismic performance of retrofitted reinforced concrete frames',
        'Leadership and knowledge sharing in engineering consultancies',
        'Vibration-based damage detection in composite pipelines',
        'Power quality improvement with grid-forming inverters',
        'Ionic liquids for desulphurisation of diesel fuel',
        'Deep learning for wellbore stability prediction',
        'Life-cycle assessment of palm oil biodiesel production',
        'Traffic flow prediction with spatio-temporal attention models',
        'Thermal management of lithium-ion battery packs',
        'Corporate governance and sustainability disclosure in Bursa Malaysia firms',
        'Adsorption of heavy metals on modified activated carbon',
    ];

    /** Result category per case; 4 goes on to a re-viva with the outcome in HISTORY_REVIVA. */
    protected const HISTORY_CATEGORIES = [2, 3, 2, 4, 1, 2, 3, 2, 3, 4, 2, 1, 3, 2, 2, 4, 3, 2, 1, 2, 3, 4, 2, 3];

    /** Case index => re-viva outcome level (1, 2, 3 or 5). */
    protected const HISTORY_REVIVA = [3 => 2, 9 => 3, 15 => 1, 21 => 5];

    /** @var array<int, User> */
    protected array $historyStudents = [];

    /** @var array<int, User> */
    protected array $reVivaDemoStudents = [];

    protected const REVIVA_BASE = 100;

    protected function historicalCases(): void
    {
        $real = Notification::getFacadeRoot();
        Notification::fake();

        $lifecycle = Examiner::query()->get(['id', 'assigned_until', 'last_examination_date'])
            ->mapWithKeys(fn (Examiner $e) => [$e->id => [
                'assigned_until' => $e->getRawOriginal('assigned_until'),
                'last_examination_date' => $e->getRawOriginal('last_examination_date'),
            ]]);

        try {
            $this->historyPeople();
            $this->resetHistory();

            foreach (array_keys(self::HISTORY_NAMES) as $i) {
                DB::transaction(fn () => $this->historicalCase($i));
            }

            // Viva results for the re-viva walkthrough students (part 5).
            foreach (array_keys($this->reVivaDemoStudents) as $k) {
                DB::transaction(fn () => $this->historicalCase(self::REVIVA_BASE + $k));
            }
        } finally {
            // The pool exactly as parts 1-3 left it.
            foreach ($lifecycle as $id => $fields) {
                Examiner::whereKey($id)->update($fields);
            }
            Notification::swap($real);
            mt_srand();
        }

        $this->command?->info(sprintf('  History: %d fictional completed cases (H01-H24) over the past year, %d through a re-viva; no email sent.',
            count(self::HISTORY_NAMES), count(self::HISTORY_REVIVA)));
    }

    /** The fictional candidates, one per case, round the eight demo departments. */
    protected function historyPeople(): void
    {
        $departments = app(Departments::class);
        $names = collect($this->dataset()['students'] ?? [])
            ->map(fn ($row) => $departments->resolve($row['department'])['name'] ?? $row['department'])
            ->unique()->values()->all();
        $supervisors = collect($this->demoUsers)->filter(fn (User $u) => $u->role === Role::SUPERVISOR);

        // One department for every walkthrough student: their panels stay
        // held for the re-viva, and a clash is only counted across
        // departments, so they can share examiners without adding one.
        // Their own supervisor, so they do not fill the scenario
        // supervisor's Students' Viva Progress.
        $reVivaSupervisor = User::updateOrCreate(['email' => 'reviva.supervisor@example.com'], [
            'name' => 'Dr Rahimah binti Yusof', 'password' => 'password', 'role' => Role::SUPERVISOR,
            'department' => $names[0] ?? 'Computing',
        ]);
        foreach (self::REVIVA_DEMO as $k => [$letter, $name]) {
            $department = $names[0] ?? 'Computing';
            $this->reVivaDemoStudents[$k] = $this->historyStudents[self::REVIVA_BASE + $k] = User::updateOrCreate(['email' => "reviva.{$letter}@example.com"], [
                'name' => $name, 'password' => 'password', 'role' => Role::STUDENT, 'matric_no' => 'RV2500'.strtoupper($letter),
                'programme' => $k % 3 === 1 ? 'PhD Full-Time' : 'MSc Full-Time', 'department' => $department,
                'supervisor_id' => $reVivaSupervisor->id,
            ]);
        }

        foreach (self::HISTORY_NAMES as $i => $name) {
            $department = $names[$i % max(1, count($names))] ?? 'Computing';
            $number = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);

            $this->historyStudents[$i] = User::updateOrCreate(['email' => "history.h{$number}@example.com"], [
                'name' => $name,
                'password' => 'password',
                'role' => Role::STUDENT,
                'matric_no' => 'H25000'.$number,
                'programme' => $i % 3 === 1 ? 'PhD Full-Time' : 'MSc Full-Time',
                'department' => $department,
                'supervisor_id' => $supervisors->first(fn (User $s) => $s->department === $department)?->id,
            ]);
        }
    }

    /** The history students' own Hani records, so each run rebuilds them. Cascades take the details and seat history. */
    protected function resetHistory(): void
    {
        $ids = collect($this->historyStudents)->pluck('id');

        Application::whereIn('student_id', $ids)
            ->whereIn('module_type', ['re_viva', VivaProgress::NOTICE, VivaProgress::SOFTBOUND, VivaProgress::NOMINATION])
            ->get()
            ->each->delete();
        VivaEligibility::whereIn('student_id', $ids)->delete();
    }

    protected function historicalCase(int $i): void
    {
        mt_srand(crc32('hani-history-'.$i));

        $student = $this->historyStudents[$i];
        $walkthrough = $i >= self::REVIVA_BASE;
        $i %= count(self::HISTORY_NAMES);
        $category = $walkthrough ? VivaDetail::REEXAMINATION : self::HISTORY_CATEGORIES[$i];
        $phd = VivaChecklist::levelFor($student) === VivaChecklist::PHD;
        $plan = $this->historyPlan($i, $category, $phd);
        $at = fn (string $event) => $plan['t0']->copy()->addMinutes((int) round($plan['events'][$event] * 60));

        $engine = app(WorkflowEngine::class);
        $ae = $this->academicExecFor($student);
        $senior = $this->reVivaSenior();
        $title = self::HISTORY_TITLES[$i];

        // ---- Notice of Thesis Submission ---------------------------------
        $notice = $this->at($at('notice'), function () use ($student, $title, $engine) {
            VivaEligibility::updateOrCreate(['student_id' => $student->id], array_fill_keys(array_keys(VivaChecklist::itemsFor($student)), true));
            $notice = Application::create(['student_id' => $student->id, 'module_type' => VivaProgress::NOTICE, 'status' => Application::STATUS_DRAFT]);
            VivaDetail::create([
                'application_id' => $notice->id,
                'programme_level' => VivaChecklist::levelFor($student),
                'thesis_title' => $title,
                'abstract' => 'Fictional abstract for the demo history: '.Str::lower($title).'.',
                'keywords' => array_slice(array_values(array_filter(explode(' ', Str::lower($title)), fn ($w) => strlen($w) > 5)), 0, 3),
                'expected_softbound_date' => now()->addMonthsNoOverflow(3)->toDateString(),
            ]);

            return $engine->submit($notice);
        });
        $this->at($at('ack'), fn () => $engine->decide($notice, $ae, 'approve'));

        // ---- Examiner nomination: the six-stage chain -----------------------
        $panel = $this->historyPanel($i, $student, $walkthrough);
        $application = $this->at($at('nominated'), function () use ($student, $title, $panel, $engine) {
            $application = Application::create([
                'student_id' => $student->id, 'submitted_by_id' => $student->supervisor_id,
                'module_type' => VivaProgress::NOMINATION, 'status' => Application::STATUS_DRAFT,
            ]);
            ExaminerNomination::create(['application_id' => $application->id, 'thesis_title' => $title] + $panel);

            return $engine->submit($application);
        });

        foreach ($plan['chain'] as [$event, $stage, $returnTo]) {
            $this->at($at($event), function () use ($application, $stage, $returnTo, $engine, $student, $senior) {
                $actor = match ($stage) {
                    'academic_exec' => $this->academicExecFor($student),
                    'senior_director' => $this->desk(Role::SENIOR_DIRECTOR_CGS, 'Senior Director (demo)'),
                    'dean' => $this->desk(Role::DEAN_PGR, 'Dean of PGR (demo)'),
                    default => $senior,
                };
                $application->refresh();
                $returnTo
                    ? $engine->returnTo($application, $actor, 'academic_exec', 'The internal backup examiner is from another department; please nominate one from the candidate\'s own department.')
                    : $engine->decide($application, $actor, 'approve');
            });
        }
        $nomination = ExaminerNomination::where('application_id', $application->id)->sole();
        $this->at($at('released'), fn () => app(PanelSeats::class)->release($nomination));

        // ---- Softbound check (returned once in some cases) ------------------
        foreach ($plan['softbound'] as [$filed, $decided, $decision]) {
            $attempt = $this->at($at($filed), function () use ($student, $notice, $title, $phd, $engine, $filed) {
                $attempt = Application::create(['student_id' => $student->id, 'module_type' => VivaProgress::SOFTBOUND, 'status' => Application::STATUS_DRAFT]);
                VivaSoftboundDetail::create([
                    'application_id' => $attempt->id, 'viva_application_id' => $notice->id,
                    'field_of_study' => 'Research', 'contact_number' => '012-345 6789',
                    'personal_email' => Str::before($student->email, '@').'.personal@example.com',
                    'session' => now()->format('F'), 'year' => now()->year,
                    'thesis_title' => $title, 'similarity_percent' => $filed === 'softbound1' ? 14 : 9,
                    'similarity_source' => 'self_declared', 'word_count' => $phd ? 78000 : 36000,
                    'declaration_accepted' => true,
                ]);

                return $engine->submit($attempt);
            });
            $this->at($at($decided), fn () => $engine->decide($attempt, $ae, $decision,
                $decision === 'reject' ? 'The Turnitin report is older than 30 days; please upload a current report.' : null));
        }

        // ---- Examiner reports, viva, result ----------------------------------
        $this->at($at('thesis'), function () use ($notice, $nomination, $at, $plan) {
            $rows = app(ExaminerProgress::class)->sync($notice, $nomination->refresh());
            foreach (['internal_main_id' => 'report_internal', 'external_main_id' => 'report_external'] as $seat => $event) {
                $rows->get($seat)?->update([
                    'letter_sent_at' => now()->subDays(6)->toDateString(), 'letter_source' => 'manual',
                    'acknowledgement' => 'accepted', 'acknowledged_at' => now()->subDays(3)->toDateString(),
                    'thesis_received_at' => now()->toDateString(),
                    'report_received_at' => $at($event)->toDateString(),
                    'category' => $plan['report_categories'][$seat],
                ]);
            }
        });

        VivaDetail::where('application_id', $notice->id)->update([
            'viva_at' => $at('viva'), 'venue' => 'Seminar Room 1, Chancellor Complex',
            'chair_name' => 'Prof Dr Mahmud bin Idris', 'chair_email' => 'mahmud.idris@example.com',
            'scheduled_by_id' => $ae->id,
        ]);
        $this->at($at('result'), fn () => $this->result($notice->refresh(), $category));

        if ($category !== VivaDetail::REEXAMINATION) {
            VivaDetail::where('application_id', $notice->id)->update([
                'corrections_submitted_at' => $at('corrections'),
                'corrections_accepted_at' => $at('accepted'),
                'corrections_accepted_by_id' => $ae->id,
            ]);

            return;
        }

        if ($walkthrough) {
            VivaDetail::where('application_id', $notice->id)->update(['resubmission_opens_at' => today()->subMonth(), 'resubmission_closes_at' => today()->addMonths(5)]);

            return;
        }

        // ---- Re-viva round 1 to its outcome ---------------------------------
        $level = self::HISTORY_REVIVA[$i];
        $detail = $this->at($at('logged'), fn () => $this->logRound($student));
        $this->at($at('letters'), fn () => $this->throughDean($detail));
        $this->at($at('letters'), fn () => $this->sendPack($detail));
        $this->at($at('evaluated'), function () use ($detail, $level) {
            $category = $level === 5 ? 4 : $level;
            $this->recordBoth($detail, $category, $level === 5 ? false : null, $category, $level === 5 ? false : null);
        });
        $this->at($at('outcome'), fn () => $this->finishRound($detail, $level, $level === 5 ? 'Both examiners agreed.' : null));

        if ($level !== 5) {
            ReVivaDetail::whereKey($detail->id)->update([
                'final_thesis_submitted_at' => $at('final'),
                'corrections_accepted_at' => $at('final')->addDays(2),
                'corrections_accepted_by_id' => $ae->id,
            ]);
        }
    }

    /**
     * When everything in case $i happens, in hours from its start (t0), and
     * t0 itself, chosen so the case completes ($endDaysAgo) between 5 and
     * about 330 days ago. Draws from the case's seeded sequence.
     *
     * @return array{t0: Carbon, events: array<string, float>, chain: array<int, array{0: string, 1: string, 2: bool}>, softbound: array<int, array{0: string, 1: string, 2: string}>, report_categories: array<string, int>}
     */
    protected function historyPlan(int $i, int $category, bool $phd): array
    {
        $days = fn (int $min, int $max) => mt_rand($min * 24, $max * 24) + mt_rand(0, 59) / 60;
        $events = ['notice' => 0.0];
        $events['ack'] = $days(1, 9);
        $t = $events['nominated'] = $events['ack'] + $days(2, 12);

        // The chain. Every fifth list from the third is sent back once, by
        // the Senior Director (even cases) or the Dean (odd), and replays.
        $returnAt = $i % 5 === 2 ? ($i % 2 === 0 ? 'senior_director' : 'dean') : null;
        $durations = ['academic_exec' => [1, 7], 'cgs_compile' => [3, 16], 'senior_director' => [1, 6], 'dean' => [2, 11], 'cgs_final' => [1, 3]];
        $chain = [];
        $order = array_keys($durations);
        $n = 0;
        foreach ($order as $stage) {
            $t += $days(...$durations[$stage]);
            $chain[] = [$e = 'chain'.$n++, $stage, $stage === $returnAt];
            $events[$e] = $t;

            if ($stage === $returnAt) {
                foreach (array_slice($order, 0, array_search($stage, $order, true) + 1) as $replay) {
                    $t += $days(1, $replay === 'cgs_compile' ? 6 : 4);
                    $chain[] = [$e = 'chain'.$n++, $replay, false];
                    $events[$e] = $t;
                }
            }
        }
        // Finalise and release: one Approve records cgs_final and cgs_release.
        $t += 0.2;
        $chain[] = [$e = 'chain'.$n++, 'cgs_release', false];
        $events[$e] = $events['released'] = $t;

        // The softbound, filed about three months after the notice; every
        // sixth from the fifth is returned once and filed again.
        $softbound = [];
        $events['softbound1'] = $days(60, 90);
        $events['softbound1_decided'] = $events['softbound1'] + $days(1, 8);
        if ($i % 6 === 4) {
            $softbound[] = ['softbound1', 'softbound1_decided', 'reject'];
            $events['softbound2'] = $events['softbound1_decided'] + $days(5, 14);
            $events['softbound2_decided'] = $events['softbound2'] + $days(1, 5);
            $softbound[] = ['softbound2', 'softbound2_decided', 'approve'];
        } else {
            $softbound[] = ['softbound1', 'softbound1_decided', 'approve'];
        }
        $ready = max($events['released'], end($softbound) ? $events[end($softbound)[1]] : 0);

        // Examiner reports: PhD examiners get longer, and some run late.
        $events['thesis'] = $ready + $days(2, 5);
        $range = $phd ? [20, 52] : [15, 36];
        $events['report_internal'] = $events['thesis'] + $days(...$range);
        $events['report_external'] = $events['thesis'] + $days(...$range);
        $events['viva'] = floor((max($events['report_internal'], $events['report_external']) + $days(7, 21)) / 24) * 24 + 10;
        $events['result'] = $events['viva'] + $days(1, 3);

        if ($category !== VivaDetail::REEXAMINATION) {
            $events['corrections'] = $events['result'] + $days(7, 25);
            $events['accepted'] = $end = $events['corrections'] + $days(1, 6);
        } else {
            // The resubmission window opens six months after the viva.
            $events['logged'] = $events['viva'] + 183 * 24 + $days(3, 20);
            $events['letters'] = $events['logged'] + $days(1, 4);
            $events['reviewed'] = $events['letters'] + $days(22, 45);
            $events['evaluated'] = $events['reviewed'] + $days(3, 10);
            $events['consolidated'] = $events['evaluated'] + $days(4, 14);
            $events['outcome'] = $events['consolidated'] + $days(0, 2) + 2;
            $events['final'] = $events['outcome'] + $days(10, 30);
            $end = (self::HISTORY_REVIVA[$i] ?? 5) === 5 ? $events['outcome'] : $events['final'] + 48;
        }

        $finish = today()->subDays(5 + $i * 14)->setTime(16, 0);
        $categories = [$category, $category];
        if ($i % 4 === 1 && $category < 4) {
            $categories[1] = $category + 1;   // the examiners differed by one
        }

        return [
            't0' => $finish->copy()->subMinutes((int) round($end * 60)),
            'events' => $events,
            'chain' => $chain,
            'softbound' => $softbound,
            'report_categories' => ['internal_main_id' => $categories[0], 'external_main_id' => $categories[1]],
        ];
    }

    /**
     * The four seats: internals from the candidate's own department, as the
     * nomination form requires; externals round the pool. Inactive and
     * Unavailable examiners are left out.
     *
     * A walkthrough panel stays held (the same panel re-examines), so it
     * keeps clear of every examiner a live scenario panel names: otherwise
     * Conflict Detection would show clashes the scenarios never set up (S09
     * picked up extra ones). Falls back to the whole pool only if nothing
     * is free.
     *
     * @return array<string, ?int>
     */
    protected function historyPanel(int $i, User $student, bool $walkthrough = false): array
    {
        $departments = app(Departments::class);
        $taken = $walkthrough ? $this->scenarioExaminerIds() : [];
        $usable = function ($q) use ($taken) {
            $pool = $q->where('is_active', true)->orderBy('id')->get()
                ->filter(fn (Examiner $e) => $e->state() !== Examiner::STATE_UNAVAILABLE)->values();
            $free = $pool->reject(fn (Examiner $e) => in_array($e->id, $taken, true))->values();

            return $free->isEmpty() ? $pool : $free;
        };

        $internal = $usable(Examiner::where('type', Examiner::TYPE_INTERNAL))
            ->filter(fn (Examiner $e) => $departments->same($e->department, $student->department))->values();
        $external = $usable(Examiner::where('type', Examiner::TYPE_EXTERNAL));

        $pick = fn ($pool, int $at) => $pool->isEmpty() ? null : $pool[$at % $pool->count()]->id;
        $internalBackup = $internal->count() > 1 ? $pick($internal, $i + 1) : null;
        $externalBackup = $external->count() > 1 ? $pick($external, $i * 3 + 1) : null;

        return [
            'internal_main_id' => $pick($internal, $i),
            'internal_backup_id' => $internalBackup,
            'external_main_id' => $pick($external, $i * 3),
            'external_backup_id' => $externalBackup,
        ];
    }

    /** @return array<int, int> examiners named by the live scenario panels (not history, not walkthrough) */
    protected function scenarioExaminerIds(): array
    {
        return ExaminerNomination::whereHas('application.student', fn ($q) => $q
            ->where('email', 'not like', 'history.%')->where('email', 'not like', 'reviva.%'))
            ->get()
            ->flatMap(fn (ExaminerNomination $n) => collect(array_keys(ExaminerNomination::SLOTS))->map(fn ($seat) => $n->{$seat}))
            ->filter()->unique()->values()->all();
    }
}
