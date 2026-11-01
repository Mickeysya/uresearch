<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyDismissalExport;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Dismissal List: students whose candidacy has expired, always computed live (nothing is stored, nothing is locked).
 *
 * A student is a candidate when ALL are true: today (Asia/Kuala_Lumpur) is AFTER the current expiry date; no Softbound submitted; no appeal in
 * review (with supervisor, chair, CGS or dean); not completed, not inactive/deferred and not already dismissed (all read through
 * StudentStatusReader); a research programme (config candidacy.dismissal.include_coursework = false). A Dean rejection alone does NOT make a
 * student a candidate (Handbook 4.3): an appeal still in review on the expiry day keeps the student off the list until it is decided.
 *
 * The same rule answers the upload preview (classify()), so the list, the export and the upload can never disagree.
 */
final class CandidacyDismissalList
{
    public const TZ = 'Asia/Kuala_Lumpur';

    public const OUTCOMES = ['none' => 'None', 'approved' => 'Approved earlier', 'rejected' => 'Rejected', 'closed_expired' => 'Closed expired', 'returned' => 'Returned (open)'];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::createFromInterface(\Illuminate\Support\Carbon::parse('now', self::TZ)->startOfDay());
    }

    /** @return list<array{kind:string,identity:StudentIdentity,candidacy:?StudyCandidacy,demo:?DemoStudentProfile,expiry:?CarbonImmutable,programme:?string,key:string}> */
    public static function subjects(): array
    {
        $list = [];

        foreach (StudyCandidacy::with('student')->orderBy('id')->get() as $c) {
            if ($c->student) {
                $list[] = ['kind' => 'user', 'key' => 'c'.$c->id, 'identity' => StudentDirectory::fromUser($c->student), 'candidacy' => $c, 'demo' => null,
                    'expiry' => $c->candidacy_expiry_date ? CarbonImmutable::parse($c->candidacy_expiry_date->toDateString(), self::TZ) : null, 'programme' => $c->student->programme];
            }
        }

        if (config('candidacy.reminders.demo_students')) {
            foreach (DemoStudentProfile::withoutGlobalScopes()->orderBy('id')->get() as $p) {
                $list[] = ['kind' => 'demo', 'key' => 'd'.$p->id, 'identity' => StudentDirectory::fromDemoProfile($p), 'candidacy' => null, 'demo' => $p,
                    'expiry' => $p->candidacy_expiry_date ? CarbonImmutable::parse($p->candidacy_expiry_date->toDateString(), self::TZ) : null, 'programme' => $p->programme];
            }
        }

        return $list;
    }

    /**
     * @param  array<string,mixed>  $s
     * @param  Collection<int, CandidacyAppeal>|null  $appeals  this student's non-draft appeals (loaded when null)
     * @return array{candidate:bool,reason:?string,code:string}
     */
    public static function evaluate(array $s, CarbonImmutable $today, ?Collection $appeals = null): array
    {
        $no = fn (string $code, string $reason) => ['candidate' => false, 'reason' => $reason, 'code' => $code];

        $status = StudentStatusReader::for($s['identity']);

        if ($status->dismissed) {
            return $no('dismissed', 'Already dismissed');
        }

        if (! config('candidacy.dismissal.include_coursework', false) && ! ProgrammeType::receivesReminders($s['programme'])) {
            return $no('coursework', 'Coursework student (not listed for dismissal)');
        }

        if (! $s['expiry']) {
            return $no('no_expiry', 'No expiry date on record');
        }

        if ($today->toDateString() <= $s['expiry']->toDateString()) {
            return $no('not_expired', 'Candidacy not expired yet (expires '.$s['expiry']->format('j M Y').')');
        }

        foreach (['softbound' => [$status->softboundSubmitted, 'Softbound submitted'], 'completed' => [$status->completed, 'Completed'], 'inactive' => [$status->inactive, 'Inactive or deferred']] as $code => [$hit, $why]) {
            if ($hit) {
                return $no($code, $why);
            }
        }

        $appeals ??= self::appealsFor($s);

        if ($appeals->contains(fn ($a) => in_array($a->status, [CandidacyAppeal::STATUS_WITH_SUPERVISOR, CandidacyAppeal::STATUS_WITH_CHAIR, CandidacyAppeal::STATUS_WITH_CGS, CandidacyAppeal::STATUS_WITH_DEAN], true))) {
            return $no('appeal_in_review', 'Appeal in review');
        }

        return ['candidate' => true, 'reason' => null, 'code' => 'candidate'];
    }

    /** @return Collection<int, CandidacyAppeal> */
    protected static function appealsFor(array $s): Collection
    {
        return CandidacyAppeal::query()
            ->when($s['candidacy'], fn ($q) => $q->where('student_id', $s['candidacy']->student_id))
            ->when($s['demo'], fn ($q) => $q->where('demo_profile_id', $s['demo']->id))
            ->where('status', '!=', CandidacyAppeal::STATUS_DRAFT)->orderBy('id')->get();
    }

    /** Latest non-draft appeal decides the label: rejected / closed expired / returned, else approved earlier, else none. */
    public static function outcomeOf(Collection $appeals): string
    {
        $last = $appeals->last();

        return match (true) {
            $last === null => 'none',
            $last->status === CandidacyAppeal::STATUS_REJECTED => 'rejected',
            $last->status === CandidacyAppeal::STATUS_CLOSED_EXPIRED => 'closed_expired',
            $last->status === CandidacyAppeal::STATUS_RETURNED => 'returned',
            $appeals->contains('status', CandidacyAppeal::STATUS_APPROVED) => 'approved',
            default => 'none',
        };
    }

    public static function levelOf(?string $programme): string
    {
        $entry = CandidacyDepartmentResolver::catalogueEntry($programme);

        if ($entry) {
            return $entry['level'] === 'phd' ? 'phd' : 'master';
        }

        return preg_match('/\b(ph\.?d|doctor)/i', (string) $programme) === 1 ? 'phd' : 'master';
    }

    /**
     * Every student with a row of data about them: the candidates (candidate = true) and the others with the reason. One query per table.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public static function all(?CarbonImmutable $today = null): Collection
    {
        $today ??= self::today();
        $appeals = CandidacyAppeal::where('status', '!=', CandidacyAppeal::STATUS_DRAFT)->orderBy('id')->get();
        $byUser = $appeals->whereNotNull('student_id')->groupBy('student_id');
        $byDemo = $appeals->whereNotNull('demo_profile_id')->groupBy('demo_profile_id');
        $departments = CandidacyDepartment::all()->keyBy('code');
        $exports = CandidacyDismissalExport::query()->selectRaw('candidacy_id, demo_profile_id, max(exported_at) as at')->groupBy('candidacy_id', 'demo_profile_id')->get();
        $exportedUser = $exports->whereNotNull('candidacy_id')->pluck('at', 'candidacy_id');
        $exportedDemo = $exports->whereNotNull('demo_profile_id')->pluck('at', 'demo_profile_id');

        return collect(self::subjects())->map(function (array $s) use ($today, $byUser, $byDemo, $departments, $exportedUser, $exportedDemo) {
            $mine = $s['candidacy'] ? ($byUser[$s['candidacy']->student_id] ?? collect()) : ($byDemo[$s['demo']->id] ?? collect());
            $verdict = self::evaluate($s, $today, $mine);
            $id = $s['identity'];
            $department = $s['candidacy']
                ? CandidacyDepartmentResolver::forCandidacy($s['candidacy'])
                : ($departments[CandidacyDepartmentResolver::codeFor($s['programme'], $id->studentId)] ?? null);
            $at = $s['candidacy'] ? ($exportedUser[$s['candidacy']->id] ?? null) : ($exportedDemo[$s['demo']->id] ?? null);

            return $s + $verdict + [
                'studentId' => $id->studentId, 'name' => $id->name, 'level' => self::levelOf($s['programme']),
                'department' => $department, 'daysSince' => $s['expiry'] ? max(0, (int) $s['expiry']->diffInDays($today)) : null,
                'outcome' => self::outcomeOf($mine), 'appeal' => $mine->last(),
                'exportedAt' => $at ? \Illuminate\Support\Carbon::parse($at, self::TZ) : null,
            ];
        })->values();
    }

    /** @return Collection<int, array<string,mixed>> the candidates only, longest expired first */
    public static function candidates(?CarbonImmutable $today = null): Collection
    {
        return self::all($today)->where('candidate', true)->sortBy([['daysSince', 'desc'], ['studentId', 'asc']])->values();
    }

    /** @return array{q:?string,department:?int,level:?string,outcome:?string,exported:?string,group:?string} */
    public static function filters(array $q): array
    {
        $pick = fn (string $k, array $allowed) => in_array($q[$k] ?? null, $allowed, true) ? $q[$k] : null;

        return [
            'q' => isset($q['q']) && trim((string) $q['q']) !== '' ? mb_substr(trim((string) $q['q']), 0, 80) : null,
            'department' => isset($q['department']) && ctype_digit((string) $q['department']) ? (int) $q['department'] : null,
            'level' => $pick('level', ['master', 'phd']),
            'outcome' => $pick('outcome', array_keys(self::OUTCOMES)),
            'exported' => $pick('exported', ['yes', 'no']),
            'group' => $pick('group', ['not_exported', 'exported']),
        ];
    }

    /** @param Collection<int, array<string,mixed>> $rows */
    public static function filter(Collection $rows, array $f, bool $withGroup = true): Collection
    {
        return $rows->filter(function (array $r) use ($f, $withGroup) {
            if ($f['q'] && ! str_contains(mb_strtolower($r['name'].' '.$r['studentId']), mb_strtolower($f['q']))) {
                return false;
            }

            if ($f['department'] && $r['department']?->id !== $f['department']) {
                return false;
            }

            if ($f['level'] && $r['level'] !== $f['level']) {
                return false;
            }

            if ($f['outcome'] && $r['outcome'] !== $f['outcome']) {
                return false;
            }

            if ($f['exported'] && ($f['exported'] === 'yes') !== ($r['exportedAt'] !== null)) {
                return false;
            }

            if ($withGroup && $f['group'] && ($f['group'] === 'exported') !== ($r['exportedAt'] !== null)) {
                return false;
            }

            return true;
        })->values();
    }

    /** The three cards, for the current filters (without the card's own group). @return array{candidates:int,not_exported:int,exported:int} */
    public static function counts(Collection $candidates, array $f): array
    {
        $base = self::filter($candidates, $f, false);

        return ['candidates' => $base->count(), 'not_exported' => $base->whereNull('exportedAt')->count(), 'exported' => $base->whereNotNull('exportedAt')->count()];
    }

    public static function version(): string
    {
        return sha1(json_encode(self::candidates()->map(fn ($r) => [$r['key'], $r['exportedAt']?->timestamp, $r['outcome']])->all()));
    }

    /** Where the student's latest appeal opens, or null. */
    public static function detailUrl(array $r, string $returnTo): ?string
    {
        return $r['appeal'] ? route('candidacy.appeals.show', ['appeal' => $r['appeal']->id, 'return' => $returnTo]) : null;
    }
}
