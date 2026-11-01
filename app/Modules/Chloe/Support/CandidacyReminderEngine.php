<?php

namespace App\Modules\Chloe\Support;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyReminderLog;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyExpiryReminder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Automatic candidacy expiry reminders (Handbook 2.1, 2.1.1, 5.3 ix, 4.3).
 *
 * A student is eligible when: research programme (not Coursework); has a current expiry date; today is ON OR BEFORE it; and none of Softbound
 * submitted / completed / dismissed / inactive-or-deferred (StudentStatusReader). An appeal in review (with supervisor, chair, CGS or dean)
 * PAUSES reminders; they resume when the appeal is returned (variant C), rejected or approved.
 *
 * Milestones: expiry date minus 3, 2 and 1 calendar months (no month overflow). A milestone goes out on the first run on or after its date. If
 * several are due and unsent, ONLY the latest is sent and the earlier ones are logged as skipped, so they never send later. One log row per
 * (candidacy or demo student, expiry date, milestone) makes a second run, or a restart, send nothing twice; a new expiry date is a new cycle.
 *
 * Variants: A normal; B no further appeal possible (12 months used, or the Dean rejected the last appeal); C an appeal is returned to the student.
 */
final class CandidacyReminderEngine
{
    public const TZ = 'Asia/Kuala_Lumpur';

    /** @return list<int> milestones (months before the expiry date), latest-first order is by the smaller number */
    public static function milestones(): array
    {
        return array_values((array) config('candidacy.reminders.milestones', [3, 2, 1]));
    }

    /** @return array<int, CarbonImmutable> months => the date that milestone falls on (no month overflow: 31 May minus 3 months = the last day of February) */
    public static function dates(CarbonImmutable $expiry): array
    {
        $out = [];

        foreach (self::milestones() as $m) {
            $out[$m] = $expiry->subMonthsNoOverflow($m);
        }

        return $out;
    }

    public static function today(?string $date = null): CarbonImmutable
    {
        // the (mutable) Carbon honours the test clock the whole application uses
        return CarbonImmutable::createFromInterface(\Illuminate\Support\Carbon::parse($date ?? 'now', self::TZ)->startOfDay());
    }

    /**
     * Everything one run would do. With $dry = false the log rows are written (once) and the notifications sent; with $dry = true nothing is.
     *
     * @return list<array<string,mixed>> one entry per student: ['action' => send|pause|stop|none, 'reason' => ..., 'milestone', 'variant', 'skipped' => [...], 'student' => [...]]
     */
    public function run(CarbonImmutable $today, bool $dry): array
    {
        $out = [];

        foreach ($this->subjects() as $subject) {
            $decision = $this->decide($subject, $today);
            $decision['student'] = ['id' => $subject['identity']->studentId, 'name' => $subject['identity']->name, 'demo' => $subject['demo'] !== null, 'expiry' => $subject['expiry']?->toDateString()];

            if ($decision['action'] === 'send' && ! $dry && ! $this->commit($subject, $decision, $today)) {
                $decision['action'] = 'none';
                $decision['reason'] = 'already recorded';
            }

            $out[] = $decision;
        }

        return $out;
    }

    /** @return list<array{identity:StudentIdentity,candidacy:?StudyCandidacy,demo:?DemoStudentProfile,expiry:?CarbonImmutable,programme:?string}> */
    protected function subjects(): array
    {
        $list = [];

        foreach (StudyCandidacy::with('student')->orderBy('id')->get() as $c) {
            if ($c->student) {
                $list[] = ['identity' => StudentDirectory::fromUser($c->student), 'candidacy' => $c, 'demo' => null, 'expiry' => $c->candidacy_expiry_date ? CarbonImmutable::parse($c->candidacy_expiry_date->toDateString(), self::TZ) : null, 'programme' => $c->student->programme];
            }
        }

        if (config('candidacy.reminders.demo_students')) {
            foreach (DemoStudentProfile::orderBy('id')->get() as $p) {
                $list[] = ['identity' => StudentDirectory::fromDemoProfile($p), 'candidacy' => null, 'demo' => $p, 'expiry' => $p->candidacy_expiry_date ? CarbonImmutable::parse(\Illuminate\Support\Carbon::parse($p->candidacy_expiry_date)->toDateString(), self::TZ) : null, 'programme' => $p->programme];
            }
        }

        return $list;
    }

    /** @param array<string,mixed> $s */
    public function decide(array $s, CarbonImmutable $today): array
    {
        $none = fn (string $action, string $reason, array $extra = []) => ['action' => $action, 'reason' => $reason, 'milestone' => null, 'variant' => null, 'skipped' => []] + $extra;

        if (! ProgrammeType::receivesReminders($s['programme'])) {
            return $none('stop', 'coursework');
        }

        if (! $s['expiry']) {
            return $none('stop', 'no expiry date');
        }

        if ($today->toDateString() > $s['expiry']->toDateString()) {
            return $none('stop', 'expired');
        }

        $status = StudentStatusReader::for($s['identity']);

        foreach (['softbound_submitted' => $status->softboundSubmitted, 'completed' => $status->completed, 'dismissed' => $status->dismissed, 'inactive' => $status->inactive] as $reason => $hit) {
            if ($hit) {
                return $none('stop', $reason === 'softbound_submitted' ? 'softbound submitted' : $reason, ['demo_data' => $status->isDemoData()]);
            }
        }

        if ($s['candidacy'] && $s['candidacy']->status !== StudyCandidacy::STATUS_ACTIVE) {
            return $none('stop', 'candidacy '.$s['candidacy']->status);
        }

        $appeals = $this->appeals($s);

        if ($appeals->contains(fn ($a) => in_array($a->status, [CandidacyAppeal::STATUS_WITH_SUPERVISOR, CandidacyAppeal::STATUS_WITH_CHAIR, CandidacyAppeal::STATUS_WITH_CGS, CandidacyAppeal::STATUS_WITH_DEAN], true))) {
            return $none('pause', 'appeal in review');
        }

        $returned = $appeals->firstWhere('status', CandidacyAppeal::STATUS_RETURNED);
        $variant = 'A';
        $blocked = null;

        if ($returned) {
            $variant = 'C';
        } elseif ($blocked = $this->blockedReason($s, $appeals)) {
            $variant = 'B';

            if (! config('candidacy.reminders.send_when_appeal_blocked', true)) {
                return $none('none', 'appeal blocked (reminders off for B)');
            }
        }

        $logged = $this->logged($s);
        $due = [];

        foreach (self::dates($s['expiry']) as $months => $date) {
            if ($date->toDateString() <= $today->toDateString() && ! in_array($months, $logged, true)) {
                $due[] = $months;
            }
        }

        if ($due === []) {
            return $none('none', 'no milestone due');
        }

        $latest = min($due);

        return [
            'action' => 'send', 'reason' => 'milestone due', 'milestone' => $latest, 'variant' => $variant, 'skipped' => array_values(array_diff($due, [$latest])),
            'blocked_reason' => $blocked, 'appeal' => $returned, 'demo_data' => $status->isDemoData(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, CandidacyAppeal> */
    protected function appeals(array $s)
    {
        return CandidacyAppeal::query()
            ->when($s['candidacy'], fn ($q) => $q->where('student_id', $s['candidacy']->student_id))
            ->when($s['demo'], fn ($q) => $q->where('demo_profile_id', $s['demo']->id))
            ->where('status', '!=', CandidacyAppeal::STATUS_DRAFT)->orderBy('id')->get();
    }

    /** "the 12-month extension allowance has been used" | "your last appeal was rejected" | null when a further appeal is possible */
    protected function blockedReason(array $s, $appeals): ?string
    {
        $used = $s['candidacy'] ? $s['candidacy']->allowanceUsed() : (int) $appeals->where('status', CandidacyAppeal::STATUS_APPROVED)->sum('approved_months');
        $rejected = $appeals->contains('final_outcome', CandidacyAppeal::OUTCOME_REJECTED_FINAL) || ($s['candidacy']?->hasFinalRejection() ?? false);

        if ($rejected) {
            return 'your last appeal was rejected';
        }

        return $used >= (int) config('candidacy.max_extension_months', 12) ? 'the 12-month extension allowance has been used' : null;
    }

    /** @return list<int> the milestones already recorded (sent OR skipped) for this expiry date */
    protected function logged(array $s): array
    {
        return CandidacyReminderLog::query()
            ->when($s['candidacy'], fn ($q) => $q->where('candidacy_id', $s['candidacy']->id))
            ->when($s['demo'], fn ($q) => $q->where('demo_profile_id', $s['demo']->id))
            ->whereDate('expiry_date', $s['expiry']->toDateString())->pluck('milestone')->map(fn ($m) => (int) $m)->all();
    }

    /** Record (once) and send. @return bool false when the rows already existed (another run got there first) */
    protected function commit(array $s, array $d, CarbonImmutable $today): bool
    {
        $keys = ['candidacy_id' => $s['candidacy']?->id, 'demo_profile_id' => $s['demo']?->id, 'student_id' => $s['candidacy']?->student_id, 'expiry_date' => $s['expiry']->toDateString()];

        try {
            DB::transaction(function () use ($keys, $d) {
                foreach ($d['skipped'] as $m) {
                    CandidacyReminderLog::create($keys + ['milestone' => $m, 'variant' => $d['variant'], 'status' => CandidacyReminderLog::SKIPPED]);
                }

                CandidacyReminderLog::create($keys + ['milestone' => $d['milestone'], 'variant' => $d['variant'], 'status' => CandidacyReminderLog::SENT, 'sent_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            return false; // never twice
        }

        $s['identity']->notify(new CandidacyExpiryReminder(
            variant: $d['variant'], months: $d['milestone'], name: $s['identity']->name, expiry: $s['expiry'],
            remaining: max(0, (int) config('candidacy.max_extension_months', 12) - ($s['candidacy'] ? $s['candidacy']->allowanceUsed() : (int) $this->appeals($s)->where('status', CandidacyAppeal::STATUS_APPROVED)->sum('approved_months'))),
            blockedReason: $d['blocked_reason'] ?? null, appeal: $d['appeal'] ?? null,
        ));

        return true;
    }
}
