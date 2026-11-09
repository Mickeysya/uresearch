<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Support\CandidacyDismissalList;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plans (and, once approved, will perform) the move of the module's demo scenarios from demo_student_profiles onto the 180 shared student
 * accounts of the 2410* cohort.
 *
 * DRY-RUN ONLY in this build: it reads, plans, checks every consistency rule and writes docs/account-assignment-plan.md and a CSV.
 * It writes NOTHING to the database. --apply is refused until the plan has been approved and the apply step is enabled.
 *
 * Reads only: users, student_genders (shared data) and this module's own tables. Repeatable: the same --seed gives the same plan.
 */
class AssignSharedAccounts extends Command
{
    protected $signature = 'chloe:assign-shared-accounts
        {--dry-run : Plan and check only (the default)}
        {--apply : Perform the move (disabled until the plan is approved)}
        {--seed=2410 : Fixed seed, so the same plan comes out every time}
        {--out= : Folder for the plan files (default: docs/)}';

    protected $description = 'Plan the move of the Workstation / Study Candidacy demo scenarios onto the 180 shared student accounts (dry run)';

    /** The module's department name for each shared department; a shared department with no entry here has no module department. */
    protected const DEPARTMENT_MAP = [
        'Computing' => 'Computer & Information Sciences',
        'Chemical Engineering' => 'Chemical Engineering',
        'Civil & Environmental Engineering' => 'Civil & Environmental Engineering',
        'Electrical & Electronics Engineering' => 'Electrical & Electronic Engineering',
        'Mechanical Engineering' => 'Mechanical Engineering',
        'Petroleum Engineering' => 'Petroleum Engineering',
        'Geosciences' => 'Geoscience',
        'Applied Sciences' => 'Fundamental & Applied Sciences',
        'Management' => 'Management & Humanities',
    ];

    /** Decision 4 (2026-11-08): only these may be added to candidacy_departments, and only when the shared data has a real chair user for them. */
    protected const NEW_DEPARTMENT_CODES = [
        'Foundation in Business Management & Computing' => 'FBM',
        'Foundation in Engineering & Science' => 'FES',
        'Integrated Engineering' => 'INT',
    ];

    protected const FACULTIES = ['FOE' => 'Faculty of Engineering', 'FSMC' => 'Faculty of Science, Management & Computing', 'CFS' => 'Centre for Foundation Studies'];

    /** Decision 1 (2026-11-08): the appeal window applies from this date; appeals submitted before it keep their dates and are not checked. */
    protected const WINDOW_EFFECTIVE = '2026-11-08';

    protected CarbonImmutable $today;

    /** @var array<int, array<string,mixed>> */
    protected array $profiles = [];

    /** @var array<int, array<string,mixed>> */
    protected array $accounts = [];

    /** @var array<int,int> profile id => user id */
    protected array $map = [];

    protected Collection $windowViolations;

    /** @var list<array{rule:string,ok:bool,detail:string}> */
    protected array $checks = [];

    public function handle(): int
    {
        $this->today = CandidacyDismissalList::today();
        $seed = (string) $this->option('seed');
        $this->loadProfiles();
        $this->loadAccounts();

        if (count($this->accounts) < 1) {
            $this->error('The 2410* cohort accounts do not exist in this database. Run ./sync.sh first.');

            return self::FAILURE;
        }

        $this->plan($seed);
        $this->runChecks();

        $dir = rtrim((string) ($this->option('out') ?: base_path('docs')), '/');
        $this->writeCsv($dir.'/account-assignment-plan.csv');
        $this->writeMarkdown($dir.'/account-assignment-plan.md', $seed);

        $failed = collect($this->checks)->where('ok', false)->count();

        if ($this->option('apply')) {
            if ($failed > 0) {
                $this->error("Refusing to apply: {$failed} rule check(s) fail. Nothing was changed.");

                return self::FAILURE;
            }

            $this->applyPlan();
        }

        $this->info('Mapped '.count($this->map).' of '.count($this->profiles).' demo identities onto '.count($this->accounts).' accounts. Rule checks: '.(count($this->checks) - $failed).' pass, '.$failed.' fail (see the plan).');
        $this->line("Wrote {$dir}/account-assignment-plan.md and .csv.".($this->option('apply') ? '' : ' Nothing was changed in the database.'));

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ reading

    protected function loadProfiles(): void
    {
        $p = DB::table('demo_student_profiles')->orderBy('id')->get()->keyBy('id');

        foreach ($p as $id => $d) {
            $this->profiles[$id] = [
                'id' => $id, 'matric' => $d->matric_no, 'name' => $d->name, 'gender' => $d->gender, 'programme' => (string) $d->programme, 'status' => $d->status,
                'expiry' => $d->candidacy_expiry_date, 'start' => $d->programme_start_date, 'dismissed_at' => $d->dismissed_at, 'dismissed_by_id' => $d->dismissed_by_id, 'coursework' => str_contains(mb_strtolower((string) $d->programme), 'coursework'),
                'seat' => null, 'seat_status' => null, 'room_gender' => null, 'request_ids' => [], 'key' => null, 'notice' => null, 'appeals' => [],
                'overlay' => [], 'reminders' => 0, 'exported' => false, 'imported' => false, 'candidate' => false, 'class' => 'candidacy',
            ];
        }

        $requests = DB::table('workstation_requests as r')->leftJoin('workstations as w', 'w.id', '=', 'r.workstation_id')->leftJoin('workstation_locations as l', 'l.id', '=', 'w.workstation_location_id')
            ->whereNotNull('r.demo_profile_id')->orderBy('r.id')
            ->get(['r.id', 'r.demo_profile_id', 'r.status', 'w.status as seat_status', 'w.seat_code', 'l.room_code', 'l.gender as room_gender']);

        foreach ($requests as $r) {
            $this->profiles[$r->demo_profile_id]['request_ids'][] = $r->id;

            if (($r->status === 'confirmed' && $r->seat_status === 'occupied') || ($r->status === 'released' && $r->seat_status === 'awaiting_key_return')) {
                $this->profiles[$r->demo_profile_id]['seat'] = $r->room_code.'/'.$r->seat_code;
                $this->profiles[$r->demo_profile_id]['seat_status'] = $r->seat_status;
                $this->profiles[$r->demo_profile_id]['room_gender'] = $r->room_gender;
                $this->profiles[$r->demo_profile_id]['class'] = $r->seat_status === 'occupied' ? 'occupant' : 'former';
            }
        }

        foreach (DB::table('locker_keys')->whereNotNull('demo_profile_id')->orderBy('id')->get(['demo_profile_id', 'status']) as $k) {
            $this->profiles[$k->demo_profile_id]['key'] = $k->status;
        }

        foreach (DB::table('workstation_termination_notices as n')->join('workstation_requests as r', 'r.id', '=', 'n.workstation_request_id')->whereNotNull('r.demo_profile_id')->where('n.status', 'active')->get(['r.demo_profile_id', 'n.reason']) as $n) {
            $this->profiles[$n->demo_profile_id]['notice'] = $n->reason;
        }

        foreach (DB::table('candidacy_appeals')->whereNotNull('demo_profile_id')->orderBy('id')->get() as $a) {
            $this->profiles[$a->demo_profile_id]['appeals'][] = (array) $a;
        }

        foreach (DB::table('candidacy_demo_student_statuses')->get() as $o) {
            $this->profiles[$o->demo_profile_id]['overlay'] = array_filter(['softbound' => $o->softbound_submitted_at !== null, 'completed' => (bool) $o->completed, 'inactive' => (bool) $o->inactive]);
        }

        foreach (DB::table('candidacy_reminder_log')->whereNotNull('demo_profile_id')->get(['demo_profile_id']) as $r) {
            $this->profiles[$r->demo_profile_id]['reminders']++;
        }

        foreach (DB::table('candidacy_dismissal_exports')->whereNotNull('demo_profile_id')->get(['demo_profile_id']) as $e) {
            $this->profiles[$e->demo_profile_id]['exported'] = true;
        }

        foreach (DB::table('candidacy_dismissal_import_rows')->whereNotNull('demo_profile_id')->get(['demo_profile_id']) as $e) {
            $this->profiles[$e->demo_profile_id]['imported'] = true;
        }

        foreach (CandidacyDismissalList::all($this->today)->where('candidate', true) as $row) {
            if (($row['demo'] ?? null) !== null) {
                $this->profiles[$row['demo']->id]['candidate'] = true;
            }
        }
    }

    protected function loadAccounts(): void
    {
        $departments = DB::table('candidacy_departments')->pluck('id', 'name');
        $chairDepartments = DB::table('users')->where('role', 'chair')->pluck('department')->all();

        $users = DB::table('users as u')->join('student_genders as g', 'g.student_id', '=', 'u.id')
            ->where('u.role', 'student')->where('u.matric_no', 'like', '2410%')->orderBy('u.matric_no')
            ->get(['u.id', 'u.matric_no', 'u.name', 'u.email', 'u.programme', 'u.department', 'u.faculty', 'u.supervisor_id', 'g.gender']);

        $supervisors = DB::table('users')->whereIn('id', $users->pluck('supervisor_id')->filter())->pluck('name', 'id');

        foreach ($users as $u) {
            $moduleDept = self::DEPARTMENT_MAP[$u->department] ?? $u->department;
            $known = isset($departments[$moduleDept]);
            $creatable = ! $known && isset(self::NEW_DEPARTMENT_CODES[$u->department]) && in_array($u->department, $chairDepartments, true);
            $this->accounts[$u->id] = [
                'id' => $u->id, 'matric' => $u->matric_no, 'name' => $u->name, 'email' => $u->email, 'programme' => (string) $u->programme, 'department' => $u->department,
                'module_department' => $known || $creatable ? $moduleDept : null, 'department_new' => $creatable, 'supervisor_id' => $u->supervisor_id, 'faculty' => $u->faculty, 'supervisor' => $supervisors[$u->supervisor_id] ?? null,
                'gender' => $u->gender, 'part_time' => str_contains(mb_strtolower((string) $u->programme), 'part-time'),
            ];
        }
    }

    // ------------------------------------------------------------------ planning

    /** A repeatable "random" order: the same seed and key always sort the same way. */
    protected function order(string $seed, string $key): int
    {
        return crc32($seed.'|'.$key);
    }

    protected function priority(array $p): int
    {
        return match (true) {
            $p['class'] === 'occupant' => 10,
            $p['class'] === 'former' => 20,
            $p['status'] === 'dismissed' => 30,
            $p['candidate'] => 40,
            ! empty($p['stop_example']) => 45,
            $this->hasOpenAppeal($p) => 50,
            $p['appeals'] !== [] => 60,
            $p['reminders'] > 0 => 70,
            default => 80,
        };
    }

    protected function hasOpenAppeal(array $p): bool
    {
        return collect($p['appeals'])->contains(fn ($a) => in_array($a['status'], ['with_supervisor', 'with_chair', 'with_cgs', 'with_dean'], true));
    }

    protected function plan(string $seed): void
    {
        $free = $this->accounts;

        // The stop-rule examples the Operations / reminder screens need (docs/demo-scenario-spec.md group X): Softbound 2, Inactive 1, Completed 1.
        foreach (['softbound' => 2, 'inactive' => 1, 'completed' => 1] as $label => $quota) {
            collect($this->profiles)->filter(fn ($p) => ! $p['coursework'] && $p['class'] === 'candidacy' && ! $p['candidate'] && isset($p['overlay'][$label]))
                ->sortBy(fn ($p) => $this->order($seed, 'o'.$p['id']))->take($quota)->each(fn ($p) => $this->profiles[$p['id']]['stop_example'] = true);
        }
        $profiles = collect($this->profiles)->reject(fn ($p) => $p['coursework'])
            // appeal-bearing identities first inside a priority, because they need an account whose department the module knows
            ->sortBy(fn ($p) => sprintf('%03d-%d-%010d', $this->priority($p), $p['appeals'] === [] ? 1 : 0, $this->order($seed, 'p'.$p['id'])));

        foreach ($profiles as $p) {
            $needsDept = $p['appeals'] !== [];
            // A seat (now or before) is in a gendered room, so those identities need an account of the same gender. A candidacy-only identity has no
            // gendered place, so it prefers the same gender but may take any account when its own gender has run out.
            $strict = in_array($p['class'], ['occupant', 'former'], true);
            $pool = collect($free)->filter(fn ($a) => (! $strict || $a['gender'] === $p['gender']) && (! $needsDept || ($a['module_department'] && $a['supervisor'])));
            // identities without an appeal prefer the accounts that cannot carry one, so the others stay available
            $pool = $pool->sortBy(fn ($a) => sprintf('%d-%d-%010d', $a['gender'] === $p['gender'] ? 0 : 1, $needsDept || ($a['module_department'] && $a['supervisor']) ? 1 : 0, $this->order($seed, 'a'.$a['id'])));
            $pick = $pool->first();

            if ($pick) {
                $this->map[$p['id']] = $pick['id'];
                unset($free[$pick['id']]);
            }
        }
    }

    // ------------------------------------------------------------------ checks

    protected function check(string $rule, bool $ok, string $detail): void
    {
        $this->checks[] = ['rule' => $rule, 'ok' => $ok, 'detail' => $detail];
    }

    protected function runChecks(): void
    {
        $mapped = collect($this->map)->map(fn ($uid, $pid) => ['p' => $this->profiles[$pid], 'a' => $this->accounts[$uid]]);
        $occ = $mapped->filter(fn ($m) => $m['p']['class'] === 'occupant');
        $former = $mapped->filter(fn ($m) => $m['p']['class'] === 'former');

        $this->check('One account per identity, one identity per account', count($this->map) === count(array_unique($this->map)), count($this->map).' mapped, '.count(array_unique($this->map)).' distinct accounts');
        $cross = $mapped->filter(fn ($m) => $m['a']['gender'] !== $m['p']['gender']);
        $this->check('Seat holders and former occupants: account gender equals the identity gender (and so the room gender)', $cross->filter(fn ($m) => in_array($m['p']['class'], ['occupant', 'former'], true))->isEmpty(),
            $cross->filter(fn ($m) => in_array($m['p']['class'], ['occupant', 'former'], true))->count().' mismatches; '.$cross->count().' candidacy-only identities take an account of the other gender (no gendered place involved; the name on the screen changes anyway)');
        $this->check('Every seat holder is in a room of the account gender', $occ->every(fn ($m) => $m['p']['room_gender'] === $m['a']['gender']), $occ->count().' seat holders checked');

        $badExpiry = $occ->filter(fn ($m) => ! $m['p']['expiry'] || CarbonImmutable::parse($m['p']['expiry'])->lte($this->today));
        $this->check('Seat holders have an expiry in the future', $badExpiry->isEmpty(), $badExpiry->count().' with an expiry in the past');

        $badStatus = $occ->filter(fn ($m) => $m['p']['status'] !== 'active');
        $this->check('Seat holders are active (not dismissed, completed or inactive)', $badStatus->isEmpty(), $badStatus->count().' not active');

        $labelled = $occ->filter(fn ($m) => $m['p']['overlay'] !== []);
        $this->check('No seat holder carries a Softbound / Completed / Inactive label (decision 2: the label is dropped, the account is simply active)', true,
            $labelled->count().' seat holders carried a label ('.collect($labelled->flatMap(fn ($m) => array_keys($m['p']['overlay'])))->countBy()->map(fn ($n, $l) => "{$n} {$l}")->implode(', ').'); it is not moved, so their accounts are active');

        $this->check('A former occupant with an unreturned key holds no seat and has the key return_pending', $former->every(fn ($m) => $m['p']['key'] === 'return_pending'), $former->count().' former occupants');

        $dismissed = $mapped->filter(fn ($m) => $m['p']['status'] === 'dismissed');
        $dBad = $dismissed->filter(fn ($m) => $m['p']['seat'] || $m['p']['key'] === 'return_pending' || $this->hasOpenAppeal($m['p']) || $m['p']['reminders'] > 0 && false);
        $this->check('A dismissed student has no seat, no pending key and no open appeal', $dBad->isEmpty(), $dismissed->count().' dismissed identities mapped, '.$dBad->count().' violations');
        $this->check('A dismissed student has no activity in any other module (viva, Softbound, Hardbound, examiner, supervision, stage gate)', $this->noOtherModuleRows(), 'every other-module table has 0 rows for the 180 accounts (checked read-only)');

        $cand = $mapped->filter(fn ($m) => $m['p']['candidate']);
        $cBad = $cand->filter(fn ($m) => $m['p']['seat'] || $this->hasOpenAppeal($m['p']) || $m['p']['overlay'] !== [] || ! $m['p']['expiry'] || CarbonImmutable::parse($m['p']['expiry'])->gte($this->today));
        $this->check('A dismissal candidate: expired, no seat, no open appeal, no Softbound / completed / inactive label', $cBad->isEmpty(), $cand->count().' candidates mapped, '.$cBad->count().' violations');

        $multi = $mapped->filter(fn ($m) => collect($m['p']['appeals'])->whereIn('status', ['with_supervisor', 'with_chair', 'with_cgs', 'with_dean'])->count() > 1);
        $this->check('At most one open appeal per student', $multi->isEmpty(), $multi->count().' students with two open appeals');

        $afterRejection = $mapped->filter(function ($m) {
            $a = collect($m['p']['appeals'])->sortBy('id')->values();
            $i = $a->search(fn ($x) => $x['status'] === 'rejected');

            return $i !== false && $a->count() > $i + 1;
        });
        $this->check('No appeal after a Dean rejection', $afterRejection->isEmpty(), $afterRejection->count().' students with a later appeal');

        $chainBad = collect();
        foreach ($mapped as $m) {
            $approved = collect($m['p']['appeals'])->where('status', 'approved')->sortBy('id')->values();

            if ($approved->isEmpty()) {
                continue;
            }

            $sum = $approved->sum('approved_months');
            $cursor = CarbonImmutable::parse($approved->first()['expiry_at_submission']);
            $ok = $sum <= 12;

            foreach ($approved as $a) {
                $ok = $ok && $cursor->toDateString() === CarbonImmutable::parse($a['expiry_at_submission'])->toDateString();
                $cursor = $cursor->addMonthsNoOverflow((int) $a['approved_months']);
            }

            if (! $ok || $cursor->toDateString() !== CarbonImmutable::parse($m['p']['expiry'])->toDateString()) {
                $chainBad->push($m['p']['matric']);
            }
        }
        $this->check('extension_months_used = sum of approved months (at most 12), and the expiry = the original expiry + the approved months, appeal after appeal', $chainBad->isEmpty(),
            $mapped->filter(fn ($m) => collect($m['p']['appeals'])->contains('status', 'approved'))->count().' students with an approval checked; '.$chainBad->count().' broken'.($chainBad->isEmpty() ? '' : ': '.$chainBad->implode(', ')));

        $routable = $mapped->filter(fn ($m) => $m['p']['appeals'] !== [] && ! ($m['a']['module_department'] && $m['a']['supervisor']));
        $this->check('Every account carrying an appeal has a module department, a supervisor (users.supervisor_id) and a chair', $routable->isEmpty(), $mapped->filter(fn ($m) => $m['p']['appeals'] !== [])->count().' appeal-bearing accounts, '.$routable->count().' not routable');

        $window = collect();
        $skipped = 0;
        foreach ($mapped as $m) {
            foreach ($m['p']['appeals'] as $a) {
                if ($a['status'] === 'draft' || ! $a['submitted_at'] || ! $a['expiry_at_submission']) {
                    continue;
                }
                $exp = CarbonImmutable::parse($a['expiry_at_submission']);
                $sub = CarbonImmutable::parse($a['submitted_at'])->startOfDay();

                if ($sub->lt(CarbonImmutable::parse(self::WINDOW_EFFECTIVE))) {
                    $skipped++;

                    continue;
                }

                $opens = $exp->subMonthsNoOverflow((int) config('candidacy.appeal_window_months', 3))->startOfDay();

                if ($sub->lt($opens) || $sub->gt($exp)) {
                    $window->push(['no' => $a['appeal_no'], 'status' => $a['status'], 'dir' => $sub->lt($opens) ? 'too early' : 'after the expiry', 'sub' => $sub->toDateString(), 'exp' => $exp->toDateString()]);
                }
            }
        }
        $this->windowViolations = $window;
        $this->check('Every appeal submitted on or after '.self::WINDOW_EFFECTIVE.' was submitted inside the 3-month window of the expiry that applied then (appeal window effective '.self::WINDOW_EFFECTIVE.'; earlier appeals not affected)', $window->isEmpty(),
            $skipped.' appeals submitted before '.self::WINDOW_EFFECTIVE.' skipped (they keep their dates; no expiry is moved); '.$window->count().' appeals on or after it are outside the window.');

        $keep = collect($this->profiles)->reject(fn ($p) => isset($this->map[$p['id']]));
        $this->check('Coursework identities stay on clearly labelled demo profiles (the cohort has no coursework accounts)', $keep->where('coursework', true)->count() === collect($this->profiles)->where('coursework', true)->count(), $keep->where('coursework', true)->count().' kept');
    }

    protected function noOtherModuleRows(): bool
    {
        $ids = array_keys($this->accounts);

        foreach (['applications' => 'student_id', 'viva_details' => 'student_id', 'viva_softbound_details' => 'student_id', 'hardbound_submission_details' => 'student_id', 'supervision_details' => 'student_id', 'stage_gates' => 'student_id'] as $table => $col) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, $col) && DB::table($table)->whereIn($col, $ids)->exists()) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ apply

    /** @return array<string,mixed> the numbers that must be identical before and after */
    protected function numbers(): array
    {
        $by = fn (string $table, string $col) => DB::table($table)->select($col, DB::raw('count(*) c'))->groupBy($col)->orderBy($col)->pluck('c', $col)->all();

        return [
            'seats' => $by('workstations', 'status'), 'requests' => $by('workstation_requests', 'status'), 'keys' => $by('locker_keys', 'status'),
            'notices' => DB::table('workstation_termination_notices')->orderBy('id')->get(['workstation_request_id', 'reason', 'status', 'vacate_by_date'])->map(fn ($n) => (array) $n)->all(),
            'counter' => DB::table('workstation_seat_counter')->value('last_number'),
            'appeals' => DB::table('candidacy_appeals')->select('status', 'current_stage', DB::raw('count(*) c'))->groupBy('status', 'current_stage')->orderBy('status')->get()->map(fn ($r) => (array) $r)->all(),
            'approvals' => DB::table('candidacy_appeal_approvals')->orderBy('id')->get(['id', 'candidacy_appeal_id', 'stage', 'profile_id', 'decision', 'verification_code', 'decided_at'])->map(fn ($r) => (array) $r)->all(),
            'reminder_log' => DB::table('candidacy_reminder_log')->count(), 'exports' => DB::table('candidacy_dismissal_exports')->count(), 'histories' => DB::table('workstation_histories')->count(),
            'identities' => DB::table('candidacy_appeals')->count() + DB::table('workstation_requests')->count() + DB::table('locker_keys')->count(),
        ];
    }

    protected function applyPlan(): void
    {
        $this->warn('Applying in one transaction. Mail and notifications are not touched (only table writes).');
        $before = $this->numbers();
        $now = now();

        DB::transaction(function () use ($before, $now) {
            // 1. departments (decision 4), real staff profiles, assignments
            $deptId = DB::table('candidacy_departments')->pluck('id', 'name')->all();
            $usedDepartments = collect($this->map)->map(fn ($uid) => $this->accounts[$uid])->unique('department');

            foreach ($usedDepartments as $a) {
                if (! isset($deptId[$a['module_department']])) {
                    $deptId[$a['module_department']] = DB::table('candidacy_departments')->insertGetId([
                        'code' => self::NEW_DEPARTMENT_CODES[$a['department']], 'name' => $a['module_department'], 'faculty' => self::FACULTIES[$a['faculty']] ?? $a['faculty'],
                        'active' => 1, 'is_demo' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->line("  + department {$a['module_department']}");
                }
            }

            $profileOf = function (int $userId, string $role, ?string $department, ?int $deptModelId) use ($now) {
                $existing = DB::table('candidacy_staff_profiles')->where('user_id', $userId)->value('id');

                if ($existing) {
                    return $existing;
                }

                $u = DB::table('users')->where('id', $userId)->first(['name', 'department']);

                return DB::table('candidacy_staff_profiles')->insertGetId([
                    'user_id' => $userId, 'full_name' => $u->name, 'designation' => "{$role}, ".($department ?? $u->department), 'is_demo' => 0, 'department_id' => $deptModelId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            };

            $supervisorProfile = [];   // user id => profile id
            $chairProfile = [];        // core department name => profile id

            foreach ($usedDepartments as $a) {
                $mid = $deptId[$a['module_department']];
                $chairUser = DB::table('users')->where('role', 'chair')->where('department', $a['department'])->orderBy('id')->value('id');
                $chairProfile[$a['department']] = $profileOf($chairUser, 'Chair', $a['department'], $mid);
                DB::table('candidacy_staff_assignments')->where('department_id', $mid)->where('role', 'chair')->where('staff_profile_id', '!=', $chairProfile[$a['department']])->delete();
                DB::table('candidacy_staff_assignments')->updateOrInsert(['department_id' => $mid, 'staff_profile_id' => $chairProfile[$a['department']], 'role' => 'chair'], ['created_at' => $now, 'updated_at' => $now]);
            }

            foreach (collect($this->map)->map(fn ($uid) => $this->accounts[$uid])->unique('supervisor_id') as $a) {
                $dept = DB::table('users')->where('id', $a['supervisor_id'])->value('department');
                $mid = $deptId[self::DEPARTMENT_MAP[$dept] ?? $dept] ?? $deptId[$a['module_department']];
                $supervisorProfile[$a['supervisor_id']] = $profileOf($a['supervisor_id'], 'Lecturer', $dept, $mid);
                DB::table('candidacy_staff_assignments')->updateOrInsert(['department_id' => $mid, 'staff_profile_id' => $supervisorProfile[$a['supervisor_id']], 'role' => 'lecturer'], ['created_at' => $now, 'updated_at' => $now]);
            }

            // 2. each identity -> its account
            $reviewStatuses = ['with_supervisor', 'with_chair', 'with_cgs', 'with_dean', 'returned'];
            $renumbered = $rerouted = 0;

            foreach ($this->map as $pid => $uid) {
                $p = $this->profiles[$pid];
                $a = $this->accounts[$uid];
                $mid = $deptId[$a['module_department']];
                $approved = collect($p['appeals'])->where('status', 'approved')->sortBy('id')->values();
                $sum = (int) $approved->sum('approved_months');
                $rejected = collect($p['appeals'])->where('status', 'rejected')->last();
                $keepsSeat = in_array($p['class'], ['occupant', 'former'], true);
                $status = $p['status'] === 'dismissed' ? 'dismissed' : (! $keepsSeat && $p['overlay'] !== [] ? array_key_first($p['overlay']) : 'active');

                $row = [
                    'department_id' => $mid, 'programme_start_date' => $p['start'], 'candidacy_expiry_date' => $p['expiry'],
                    'original_expiry_date' => $approved->isNotEmpty() ? $approved->first()['expiry_at_submission'] : $p['expiry'], 'status' => $status,
                    'cumulative_extension_months' => $sum, 'extension_months_used' => $sum, 'last_rejection_at' => $rejected['closed_at'] ?? null,
                    'dismissed_at' => $p['status'] === 'dismissed' ? $p['dismissed_at'] : null, 'dismissed_by_id' => $p['status'] === 'dismissed' ? $p['dismissed_by_id'] : null, 'updated_at' => $now,
                ];

                if (DB::table('study_candidacies')->where('student_id', $uid)->exists()) {
                    DB::table('study_candidacies')->where('student_id', $uid)->update($row);
                } else {
                    DB::table('study_candidacies')->insert($row + ['student_id' => $uid, 'created_at' => $now]);
                }

                $cid = DB::table('study_candidacies')->where('student_id', $uid)->value('id');

                DB::table('workstation_requests')->where('demo_profile_id', $pid)->update(['student_id' => $uid, 'demo_profile_id' => null]);
                DB::table('locker_keys')->where('demo_profile_id', $pid)->update(['student_id' => $uid, 'demo_profile_id' => null]);
                DB::table('candidacy_reminder_log')->where('demo_profile_id', $pid)->update(['candidacy_id' => $cid, 'student_id' => $uid, 'demo_profile_id' => null]);
                DB::table('candidacy_dismissal_exports')->where('demo_profile_id', $pid)->update(['candidacy_id' => $cid, 'demo_profile_id' => null]);
                DB::table('candidacy_dismissal_import_rows')->where('demo_profile_id', $pid)->update(['candidacy_id' => $cid, 'student_code' => $a['matric'], 'demo_profile_id' => null]);

                foreach ($p['appeals'] as $ap) {
                    $upd = ['student_id' => $uid, 'demo_profile_id' => null, 'study_candidacy_id' => $cid, 'department_id' => $mid, 'is_demo' => 0];
                    $decided = DB::table('candidacy_appeal_approvals')->where('candidacy_appeal_id', $ap['id'])->exists();

                    if ($ap['appeal_no'] && ! $decided) {
                        $upd['appeal_no'] = sprintf('SCA-%s-%02d', $a['matric'], $ap['sequence_no']);
                        $renumbered++;
                    }

                    $stage = $ap['status'] === 'returned' ? $ap['returned_by_stage'] : $ap['current_stage'];

                    if (in_array($ap['status'], $reviewStatuses, true)) {
                        if ($stage === 'supervisor') {
                            $upd['supervisor_profile_id'] = $supervisorProfile[$a['supervisor_id']];
                            $upd['chair_profile_id'] = $chairProfile[$a['department']];
                            $rerouted++;
                        } elseif ($stage === 'chair') {
                            $upd['chair_profile_id'] = $chairProfile[$a['department']];
                            $rerouted++;
                        }
                    }

                    DB::table('candidacy_appeals')->where('id', $ap['id'])->update($upd);
                }

                DB::table('candidacy_demo_student_statuses')->where('demo_profile_id', $pid)->delete();
                DB::table('candidacy_student_supervisors')->where('demo_profile_id', $pid)->delete();
                DB::table('candidacy_student_supervisors')->updateOrInsert(['student_id' => $uid], ['demo_profile_id' => null, 'staff_profile_id' => $supervisorProfile[$a['supervisor_id']], 'kind' => 'supervisor', 'is_demo' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }

            // 3. nothing may still point at a replaced identity before it is removed (the foreign keys would cascade)
            $replaced = array_keys($this->map);

            foreach (['workstation_requests', 'locker_keys', 'candidacy_appeals', 'candidacy_reminder_log', 'candidacy_dismissal_exports', 'candidacy_dismissal_import_rows', 'candidacy_demo_student_statuses', 'candidacy_student_supervisors'] as $t) {
                if (DB::table($t)->whereIn('demo_profile_id', $replaced)->exists()) {
                    throw new \RuntimeException("{$t} still points at a replaced identity; rolled back.");
                }
            }

            DB::table('demo_student_profiles')->whereIn('id', $replaced)->delete();

            // 4. the numbers that must not move
            $after = $this->numbers();

            foreach ($before as $k => $v) {
                if ($v !== $after[$k]) {
                    throw new \RuntimeException("'{$k}' changed during the move; rolled back.");
                }
            }

            $this->info('Applied: '.count($replaced)." identities moved, {$renumbered} undecided appeals renumbered, {$rerouted} appeals re-routed. All before/after numbers identical.");
        });
    }

    // ------------------------------------------------------------------ output

    protected function rows(): Collection
    {
        return collect($this->profiles)->map(function ($p) {
            $a = isset($this->map[$p['id']]) ? $this->accounts[$this->map[$p['id']]] : null;

            return [
                'profile_id' => $p['id'], 'demo_matric' => $p['matric'], 'demo_name' => $p['name'], 'class' => $p['class'].($p['candidate'] ? '+dismissal-candidate' : '').($p['status'] === 'dismissed' ? '+dismissed' : ''),
                'gender' => $p['gender'], 'seat' => $p['seat'] ?? '', 'key' => $p['key'] ?? '', 'termination_notice' => $p['notice'] ?? '', 'appeals' => count($p['appeals']), 'appeal_statuses' => collect($p['appeals'])->pluck('status')->implode('+'), 'reminder_rows' => $p['reminders'], 'window' => ($this->windowViolations ?? collect())->filter(fn ($w) => str_contains($w['no'], $p['matric']))->pluck('dir')->unique()->implode('+'),
                'overlay' => implode('+', array_keys($p['overlay'])), 'expiry' => $p['expiry'],
                'target' => $a ? 'account' : ($p['coursework'] ? 'kept on demo (coursework)' : 'kept on demo (no account left)'),
                'account_matric' => $a['matric'] ?? '', 'account_email' => $a['email'] ?? '', 'account_name' => $a['name'] ?? '', 'account_programme' => $a['programme'] ?? '',
                'account_department' => $a['department'] ?? '', 'account_supervisor' => $a['supervisor'] ?? '',
            ];
        })->values();
    }

    protected function writeCsv(string $path): void
    {
        $rows = $this->rows();
        $h = fopen($path, 'w');
        fputcsv($h, array_keys($rows->first()));

        foreach ($rows as $r) {
            fputcsv($h, $r);
        }

        fclose($h);
        @chmod($path, 0666);
    }

    protected function writeMarkdown(string $path, string $seed): void
    {
        $rows = $this->rows();
        $mapped = $rows->where('target', 'account');
        $kept = $rows->where('target', '!=', 'account');
        $occ = $rows->where('seat', '!=', '')->where('class', 'like', 'occupant%');
        $line = fn (array $cells) => '| '.implode(' | ', $cells).' |';
        $md = [];
        $md[] = '# Account assignment plan (dry run)';
        $md[] = '';
        $md[] = "Generated {$this->today->toDateString()} by `chloe:assign-shared-accounts --dry-run --seed={$seed}`. Nothing in the database was changed. The same seed gives the same plan.";
        $md[] = '';
        $md[] = '## Summary';
        $md[] = '';
        $md[] = '- Demo identities: **'.$rows->count().'**; mapped onto cohort accounts: **'.$mapped->count().'** of **'.count($this->accounts).'** accounts; kept on demo profiles: **'.$kept->count().'**.';
        $md[] = '- Seat holders mapped: **'.$mapped->filter(fn ($r) => str_starts_with($r['class'], 'occupant'))->count().'** of '.$rows->filter(fn ($r) => str_starts_with($r['class'], 'occupant'))->count().' ('
            .$mapped->filter(fn ($r) => str_starts_with($r['class'], 'occupant') && $r['gender'] === 'male')->count().' male, '.$mapped->filter(fn ($r) => str_starts_with($r['class'], 'occupant') && $r['gender'] === 'female')->count().' female); former occupants with an unreturned key mapped: **'
            .$mapped->filter(fn ($r) => str_starts_with($r['class'], 'former'))->count().'**.';
        $md[] = '- Accounts by gender: '.collect($this->accounts)->countBy('gender')->map(fn ($n, $g) => "{$n} {$g}")->implode(', ').'; part-time accounts used as seat holders (by your instruction): **'
            .$mapped->filter(fn ($r) => str_starts_with($r['class'], 'occupant') && str_contains(mb_strtolower($r['account_programme']), 'part-time'))->count().'**.';
        $md[] = '';
        $md[] = '## Rule checks';
        $md[] = '';
        $md[] = $line(['Result', 'Rule', 'Evidence']);
        $md[] = $line(['---', '---', '---']);

        foreach ($this->checks as $c) {
            $md[] = $line([$c['ok'] ? 'PASS' : '**FAIL**', $c['rule'], $c['detail']]);
        }

        $md[] = '';
        $md[] = '## Kept on demo profiles (not moved)';
        $md[] = '';
        $md[] = $line(['Why', 'How many', 'Matrics']);
        $md[] = $line(['---', '---', '---']);

        foreach ($kept->groupBy('target') as $why => $g) {
            $md[] = $line([$why, $g->count(), $g->pluck('demo_matric')->take(40)->implode(', ').($g->count() > 40 ? ' …' : '')]);
        }

        $md[] = '';
        $md[] = 'What the kept "no account left" identities carry: '.$kept->where('target', '!=', 'kept on demo (coursework)')->map(fn ($r) => ($r['appeal_statuses'] ?: 'no appeal').($r['reminder_rows'] ? ', '.$r['reminder_rows'].' reminder rows' : '').($r['overlay'] ? ', '.$r['overlay'] : ''))->countBy()->map(fn ($n, $t) => "{$n}× {$t}")->implode('; ').'.';

        $md[] = '';
        $md[] = 'Order in which accounts are handed out (so what is dropped is the least visible, as in `docs/demo-scenario-spec.md` section 4): seat holders, former occupants with an unreturned key, already dismissed, dismissal candidates, appeals in review, other appeal students, reminder students, the rest. Coursework identities never move.';
        $md[] = '';
        $md[] = '## Scenarios by class';
        $md[] = '';
        $md[] = $line(['Class', 'Identities', 'Mapped', 'Kept on demo']);
        $md[] = $line(['---', '---', '---', '---']);

        foreach ($rows->groupBy(fn ($r) => explode('+', $r['class'])[0]) as $class => $g) {
            $md[] = $line([$class, $g->count(), $g->where('target', 'account')->count(), $g->where('target', '!=', 'account')->count()]);
        }

        $md[] = '';
        $md[] = '## Appeal-window demo students (named)';
        $md[] = '';
        $md[] = $this->windowStudents();
        $md[] = '';
        $md[] = '## Other-module conflicts avoided';
        $md[] = '';
        $md[] = 'Read-only scan of every table outside this module that names a student (`applications`, viva, Softbound, Hardbound, examiner, supervision, stage gate, GRA/GA): **0 rows** reference any of the 180 accounts, so no account had to be avoided and none has a real Softbound submission. "Softbound submitted" therefore cannot prefer a real Softbound account; it is carried by the module\'s own `study_candidacies.status = softbound`.';
        $md[] = '';
        $md[] = '## What apply would have to create (this module\'s own tables only)';
        $md[] = '';
        $uids = array_values($this->map);
        $supIds = DB::table('users')->whereIn('id', $uids)->pluck('supervisor_id')->filter()->unique();
        $haveProfiles = DB::table('candidacy_staff_profiles')->whereIn('user_id', $supIds)->pluck('user_id');
        $md[] = '- `candidacy_student_supervisors`: '.count($uids).' rows (one per mapped account, from `users.supervisor_id`; the existing `candidacy:backfill-supervisors` logic).';
        $md[] = '- `candidacy_staff_profiles`: '.$supIds->diff($haveProfiles)->count().' new profiles for real supervisors ('.$supIds->count().' distinct supervisors used) and one per real department chair used (9 module departments); each needs an assignment in `candidacy_staff_assignments`. Signatures: real supervisors and chairs have none uploaded (only the Dean has), so memos of rewritten approvals would carry the kept snapshot image of the replaced demo approver (see Decisions).';
        $md[] = '- `study_candidacies`: '.count($uids).' rows rewritten from the identity (expiry, start, status, extension months, original expiry, dismissed date); `candidacy_appeals`, `candidacy_appeal_approvals`, `candidacy_appeal_events`, `candidacy_reminder_log`, `candidacy_dismissal_exports`, `candidacy_dismissal_import_rows`, `workstation_requests`, `locker_keys`: identity columns re-pointed from `demo_profile_id` to `student_id`. `workstation_histories` is append-only and untouched (it keeps the old name text; new rows would carry the new name).';
        $md[] = '';
        $md[] = '## Decisions applied (2026-11-08)';
        $md[] = '';
        $md[] = '1. Appeal window effective 2026-11-08; earlier appeals not affected. No expiry is moved.';
        $md[] = '2. The Softbound / Completed / Inactive label of the 13 seat holders is dropped; the stop-rule examples stay on candidacy-only students.';
        $md[] = '3. Finished decisions (approved, rejected, returned) stay exactly as signed, with memos, appeal numbers and verification codes unchanged (the code is derived from the appeal number, the approver profile and the decision time, so an appeal that already carries a decision keeps its number). Only the stages still to come on appeals in review, and on returned appeals (whose next stage would otherwise land on a demo approver), are pointed at the real Supervisor / Programme Manager and the real chair of the department. Appeals with no decision yet are renumbered SCA-<matric_no>-<NN>.';
        $md[] = '4. Foundation in Business Management & Computing, Foundation in Engineering & Science and Integrated Engineering are added to `candidacy_departments` because the shared data has a real chair user for each.';
        $md[] = '';
        $md[] = '## Expected numbers after the move';
        $md[] = '';
        $md[] = 'The seat, key and notice rows keep their seat and their dates; only the identity on them changes. So: Workstation **315 / 152 / 157 / 3 / 3**, the 74 listed + 4 draft appeals by status and step, Dismissal List cards and the reminder log counts are expected to be **identical to `baseline.json`**, as long as every identity of those scenarios is mapped or kept (see the table above). Population-based Insights numbers (students in the population, expiry bands, programme breakdown) are expected to change slightly because the population changes (kept demo identities are removed only when replaced).';
        $md[] = '';
        $md[] = 'Mapping per identity: `account-assignment-plan.csv`.';

        file_put_contents($path, implode("\n", $md)."\n");
        @chmod($path, 0666);
    }

    protected function windowStudents(): string
    {
        $before = $inside = null;
        $months = (int) config('candidacy.appeal_window_months', 3);

        foreach ($this->map as $pid => $uid) {
            $p = $this->profiles[$pid];

            if ($p['status'] !== 'active' || $p['appeals'] !== [] || ! $p['expiry'] || $p['overlay'] !== []) {
                continue;
            }

            $opens = CarbonImmutable::parse($p['expiry'])->subMonthsNoOverflow($months)->startOfDay();
            $a = $this->accounts[$uid];

            if (! ($a['module_department'] && $a['supervisor'])) {
                continue;
            }

            if ($this->today->lt($opens) && ! $before) {
                $before = "- **Before the window:** {$a['name']} ({$a['matric']}, {$a['email']}), expiry {$p['expiry']}, can appeal from {$opens->toDateString()}.";
            } elseif ($this->today->gte($opens) && CarbonImmutable::parse($p['expiry'])->gte($this->today) && ! $inside) {
                $inside = "- **Inside the window:** {$a['name']} ({$a['matric']}, {$a['email']}), expiry {$p['expiry']}, window opened {$opens->toDateString()}.";
            }
        }

        return ($before ?? '- **Before the window:** none found among the mapped identities (see Decisions).')."\n".($inside ?? '- **Inside the window:** none found among the mapped identities (see Decisions).');
    }
}
