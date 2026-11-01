<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyReminderLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * THE one source of every number on Candidacy Management > Insights (operational monitoring of the known expiry dates, not Business Intelligence and not a prediction).
 * The page embeds the flat rows built here and tallies them in the browser; the same rows are tallied by the aggregate methods below (used by the tests and the live
 * /stats endpoint), so the page can never disagree with itself, with the Operations cards (CandidacyOperationsQuery) or with the Dismissal List (CandidacyDismissalList).
 *
 * DEFINITIONS
 *  - Population: research candidacies (Coursework excluded: the same config as the reminders) with a current expiry date that has NOT passed (a student past the expiry
 *    date belongs to the Dismissal List), who are not dismissed, completed or inactive/deferred (StudentStatusReader).
 *  - Status category, exclusive, in this priority: softbound (Softbound submitted) > in_review (an appeal with the Supervisor, Chair, CGS or Dean) > returned (an appeal
 *    returned, waiting for the student) > none (No action yet). "High risk" = none.
 *  - Days left = current expiry date minus today, as Asia/Kuala_Lumpur calendar dates (the expiry day itself = 0).
 *  - Bands within 3 months use the reminder milestones' month arithmetic (no month overflow): m1 = up to 1 month (today .. today + 1 month), m2 = after 1 month up to
 *    2 months, m3 = after 2 months up to 3 months. A student is "expiring within 3 months" when the expiry date is on or before today + 3 months.
 */
final class CandidacyStatistics
{
    public const TZ = 'Asia/Kuala_Lumpur';

    public const CATEGORIES = ['softbound' => 'Softbound submitted', 'in_review' => 'Appeal in review', 'returned' => 'Appeal returned', 'none' => 'No action yet'];

    public const BANDS = ['m1' => 'Up to 1 month', 'm2' => '1 to 2 months', 'm3' => '2 to 3 months'];

    public const RESPONSE_DAYS = 30;

    private const IN_REVIEW = [CandidacyAppeal::STATUS_WITH_SUPERVISOR, CandidacyAppeal::STATUS_WITH_CHAIR, CandidacyAppeal::STATUS_WITH_CGS, CandidacyAppeal::STATUS_WITH_DEAN];

    public static function today(): CarbonImmutable
    {
        return CandidacyDismissalList::today();
    }

    /** The band of a number of days from today (null = more than 3 months away or already past). */
    public static function bandOf(CarbonImmutable $expiry, ?CarbonImmutable $today = null): ?string
    {
        $today ??= self::today();
        $e = $expiry->toDateString();

        return match (true) {
            $e < $today->toDateString() => null,
            $e <= $today->addMonthsNoOverflow(1)->toDateString() => 'm1',
            $e <= $today->addMonthsNoOverflow(2)->toDateString() => 'm2',
            $e <= $today->addMonthsNoOverflow(3)->toDateString() => 'm3',
            default => null,
        };
    }

    /**
     * One row per student in the population (no secrets; names and IDs are shown to CGS only).
     *
     * @return Collection<int, array<string,mixed>>
     */
    public static function population(?CarbonImmutable $today = null): Collection
    {
        $today ??= self::today();
        $appeals = CandidacyAppeal::where('status', '!=', CandidacyAppeal::STATUS_DRAFT)->orderBy('id')->get();
        $byUser = $appeals->whereNotNull('student_id')->groupBy('student_id');
        $byDemo = $appeals->whereNotNull('demo_profile_id')->groupBy('demo_profile_id');
        $log = CandidacyReminderLog::where('status', CandidacyReminderLog::SENT)->orderBy('sent_at')->get();
        $logUser = $log->whereNotNull('candidacy_id')->groupBy('candidacy_id');
        $logDemo = $log->whereNotNull('demo_profile_id')->groupBy('demo_profile_id');
        $departments = \App\Modules\Chloe\Models\CandidacyDepartment::all()->keyBy('code');
        $rows = collect();

        foreach (CandidacyDismissalList::subjects() as $s) {
            $expiry = $s['expiry'];

            if (! $expiry || $expiry->toDateString() < $today->toDateString() || ! ProgrammeType::receivesReminders($s['programme'])) {
                continue;
            }

            $status = StudentStatusReader::for($s['identity']);

            if ($status->dismissed || $status->completed || $status->inactive) {
                continue;
            }

            $mine = $s['candidacy'] ? ($byUser[$s['candidacy']->student_id] ?? collect()) : ($byDemo[$s['demo']->id] ?? collect());
            $open = $mine->first(fn ($a) => in_array($a->status, self::IN_REVIEW, true));
            $returned = $mine->firstWhere('status', CandidacyAppeal::STATUS_RETURNED);
            $category = match (true) {
                $status->softboundSubmitted => 'softbound',
                $open !== null => 'in_review',
                $returned !== null => 'returned',
                default => 'none',
            };
            $shown = $open ?? $returned ?? $mine->last();
            $sent = $s['candidacy'] ? ($logUser[$s['candidacy']->id] ?? collect()) : ($logDemo[$s['demo']->id] ?? collect());
            $sent = $sent->filter(fn ($l) => $l->expiry_date->toDateString() === $expiry->toDateString());
            $id = $s['identity'];
            $department = $s['candidacy']
                ? CandidacyDepartmentResolver::forCandidacy($s['candidacy'])
                : ($departments[CandidacyDepartmentResolver::codeFor($s['programme'], $id->studentId)] ?? null);
            $used = $s['candidacy'] ? $s['candidacy']->allowanceUsed() : (int) $mine->where('status', CandidacyAppeal::STATUS_APPROVED)->sum('approved_months');

            $rows->push([
                'key' => $s['key'], 'studentId' => $id->studentId, 'name' => $id->name, 'demo' => $s['demo'] !== null,
                'dept' => $department?->code, 'deptName' => $department?->name,
                'level' => CandidacyDismissalList::levelOf($s['programme']),
                'mode' => stripos((string) $s['programme'], 'part-time') !== false ? 'part' : 'full',
                'expiry' => $expiry->toDateString(), 'daysLeft' => (int) $today->diffInDays($expiry),
                'band' => self::bandOf($expiry, $today), 'month' => $expiry->format('Y-m'), 'category' => $category,
                'appealId' => $shown?->id, 'appealNo' => $shown?->appeal_no, 'monthsUsed' => $used,
                'reminders' => $sent->map(fn ($l) => ['m' => (int) $l->milestone, 'd' => $l->sent_at?->timezone(self::TZ)->toDateString()])->values()->all(),
                'lastReminder' => $sent->max('sent_at')?->timezone(self::TZ)->toDateString(),
            ]);
        }

        return $rows->sortBy([['daysLeft', 'asc'], ['studentId', 'asc']])->values();
    }

    /** @param Collection<int, array<string,mixed>> $rows @return array{total:int,within3:int,high_risk:int,by_category:array<string,int>,bands:array<string,array<string,int>>} */
    public static function summary(Collection $rows): array
    {
        $within = $rows->whereNotNull('band');
        $byCategory = array_fill_keys(array_keys(self::CATEGORIES), 0);

        foreach ($rows->groupBy('category') as $c => $g) {
            $byCategory[$c] = $g->count();
        }

        $bands = [];

        foreach (array_keys(self::BANDS) as $b) {
            $bands[$b] = array_fill_keys(array_keys(self::CATEGORIES), 0);

            foreach ($within->where('band', $b)->groupBy('category') as $c => $g) {
                $bands[$b][$c] = $g->count();
            }
        }

        return ['total' => $rows->count(), 'within3' => $within->count(), 'high_risk' => $byCategory['none'], 'by_category' => $byCategory, 'bands' => $bands];
    }

    /**
     * Expiries per period and status category, from the known expiry dates (a projection, not a prediction).
     *
     * @return list<array{period:string,total:int,by_category:array<string,int>}>
     */
    public static function forecast(Collection $rows, string $grain = 'month', int $limit = 12, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $key = fn (string $date) => match ($grain) {
            'year' => substr($date, 0, 4),
            'quarter' => substr($date, 0, 4).'-Q'.(int) ceil((int) substr($date, 5, 2) / 3),
            default => substr($date, 0, 7),
        };
        $periods = [];

        foreach ($rows->groupBy(fn ($r) => $key($r['expiry']))->sortKeys() as $p => $g) {
            $periods[] = ['period' => (string) $p, 'total' => $g->count(), 'by_category' => array_replace(array_fill_keys(array_keys(self::CATEGORIES), 0), $g->countBy('category')->all())];
        }

        if ($grain === 'month') {
            $out = [];

            for ($i = 0; $i < $limit; $i++) {
                $m = $today->startOfMonth()->addMonthsNoOverflow($i)->format('Y-m');
                $out[] = collect($periods)->firstWhere('period', $m) ?? ['period' => $m, 'total' => 0, 'by_category' => array_fill_keys(array_keys(self::CATEGORIES), 0)];
            }

            return $out;
        }

        return $periods;
    }

    /** Appeals in progress, exactly as the Operations "In progress" card counts them. */
    public static function appealsInProgress(): int
    {
        return CandidacyOperationsQuery::counts(CandidacyOperationsQuery::filters([]))['in_progress'];
    }

    /** Dismissal pipeline: candidates not yet exported / exported awaiting Registry (the Dismissal List's own counts) and students dismissed per month. */
    public static function dismissalPipeline(?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $counts = CandidacyDismissalList::counts(CandidacyDismissalList::candidates($today), CandidacyDismissalList::filters([]));

        return ['not_exported' => $counts['not_exported'], 'exported' => $counts['exported'], 'candidates' => $counts['candidates'], 'dismissed_per_month' => self::dismissedPerMonth($today)];
    }

    /** @return array<string,int> month (Y-m) => students dismissed, the last 12 months including this one, zero-filled */
    public static function dismissedPerMonth(?CarbonImmutable $today = null, int $months = 12): array
    {
        $today ??= self::today();
        $out = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $out[$today->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m')] = 0;
        }

        foreach (self::dismissedRows() as $r) {
            if (array_key_exists($r['month'], $out)) {
                $out[$r['month']]++;
            }
        }

        return $out;
    }

    /** @return list<array{month:string,dept:?string,level:string,mode:string}> every dismissed student (real: study_candidacies.dismissed_at; demo: the profile's) */
    public static function dismissedRows(): array
    {
        $rows = [];

        foreach (\App\Modules\Chloe\Models\StudyCandidacy::with('student')->whereNotNull('dismissed_at')->get() as $c) {
            $rows[] = ['month' => $c->dismissed_at->timezone(self::TZ)->format('Y-m'), 'dept' => CandidacyDepartmentResolver::forCandidacy($c)?->code, 'level' => CandidacyDismissalList::levelOf($c->student?->programme), 'mode' => stripos((string) $c->student?->programme, 'part-time') !== false ? 'part' : 'full'];
        }

        foreach (\App\Modules\Chloe\Models\DemoStudentProfile::withoutGlobalScopes()->whereNotNull('dismissed_at')->get() as $p) {
            $rows[] = ['month' => $p->dismissed_at->timezone(self::TZ)->format('Y-m'), 'dept' => CandidacyDepartmentResolver::codeFor($p->programme, $p->matric_no), 'level' => CandidacyDismissalList::levelOf($p->programme), 'mode' => stripos((string) $p->programme, 'part-time') !== false ? 'part' : 'full'];
        }

        return $rows;
    }

    /**
     * Reminders sent per month (last 6 months plus this one), by milestone, and how many students responded: submitted Softbound or an appeal within 30 days after the reminder.
     *
     * @return list<array{month:string,date:string,m:int,dept:?string,level:string,mode:string,category:?string,responded:bool}>
     */
    public static function reminderRows(?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $from = $today->startOfMonth()->subMonthsNoOverflow(6);
        $appeals = CandidacyAppeal::whereNotNull('submitted_at')->get();
        $pop = self::population($today)->keyBy('key');
        $subjects = collect(CandidacyDismissalList::subjects())->keyBy('key');
        $out = [];

        foreach (CandidacyReminderLog::where('status', CandidacyReminderLog::SENT)->whereNotNull('sent_at')->where('sent_at', '>=', $from)->orderBy('sent_at')->get() as $l) {
            $key = $l->candidacy_id ? 'c'.$l->candidacy_id : 'd'.$l->demo_profile_id;
            $s = $subjects[$key] ?? null;

            if (! $s) {
                continue;
            }

            $sent = CarbonImmutable::parse($l->sent_at->timezone(self::TZ)->toDateString(), self::TZ);
            $until = $sent->addDays(self::RESPONSE_DAYS)->toDateString();
            $status = StudentStatusReader::for($s['identity']);
            $softbound = $status->softboundDate && $status->softboundDate->toDateString() >= $sent->toDateString() && $status->softboundDate->toDateString() <= $until;
            $appeal = $appeals->contains(fn ($a) => ($s['candidacy'] ? $a->student_id === $s['candidacy']->student_id : $a->demo_profile_id === $s['demo']?->id)
                && $a->submitted_at->timezone(self::TZ)->toDateString() >= $sent->toDateString() && $a->submitted_at->timezone(self::TZ)->toDateString() <= $until);
            $programme = $s['programme'];
            $dept = $s['candidacy'] ? CandidacyDepartmentResolver::forCandidacy($s['candidacy'])?->code : CandidacyDepartmentResolver::codeFor($programme, $s['identity']->studentId);

            $out[] = ['month' => $sent->format('Y-m'), 'date' => $sent->toDateString(), 'm' => (int) $l->milestone, 'dept' => $dept, 'level' => CandidacyDismissalList::levelOf($programme),
                'mode' => stripos((string) $programme, 'part-time') !== false ? 'part' : 'full', 'category' => $pop[$key]['category'] ?? null, 'responded' => $softbound || $appeal];
        }

        return $out;
    }

    /** Everything the Insights page embeds and the live endpoint returns (one flat, secret-free payload; names and IDs go to CGS only). @return array<string,mixed> */
    public static function payload(?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $departments = \App\Modules\Chloe\Models\CandidacyDepartment::orderBy('name')->get(['code', 'name', 'faculty']);
        $appeals = CandidacyAppeal::whereIn('status', self::IN_REVIEW)->get()->map(function ($a) {
            $programme = $a->identity()?->programme;

            return ['id' => $a->id, 'status' => $a->status, 'dept' => $a->department?->code, 'level' => CandidacyDismissalList::levelOf($programme), 'mode' => stripos((string) $programme, 'part-time') !== false ? 'part' : 'full'];
        })->values()->all();
        $candidates = CandidacyDismissalList::candidates($today)->map(fn ($r) => ['dept' => $r['department']?->code, 'level' => $r['level'], 'mode' => stripos((string) $r['programme'], 'part-time') !== false ? 'part' : 'full', 'exported' => $r['exportedAt'] !== null])->values()->all();

        return [
            'today' => $today->toDateString(), 'now' => now(self::TZ)->format('H:i:s'),
            'rows' => self::population($today)->all(), 'appeals' => $appeals, 'candidates' => $candidates,
            'dismissed' => self::dismissedRows(), 'reminders' => self::reminderRows($today),
            'departments' => $departments->map(fn ($d) => ['code' => $d->code, 'name' => $d->name, 'faculty' => $d->faculty])->all(),
            'categories' => self::CATEGORIES, 'bands' => self::BANDS,
        ];
    }

    /** A hash of the payload without the clock: changes when and only when a number on the page would change. */
    public static function version(?CarbonImmutable $today = null): string
    {
        $p = self::payload($today);
        unset($p['now']);

        return sha1(json_encode($p));
    }
}
