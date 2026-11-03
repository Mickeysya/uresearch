<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\SuppliesAllowanceStatus;
use App\Modules\Core\Contracts\SuppliesCandidacyStatus;
use App\Modules\Core\Contracts\SuppliesDecisionDates;
use App\Modules\Core\Contracts\SuppliesDraftCount;
use App\Modules\Core\Contracts\SuppliesVivaStatus;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Concerns\BuildsPanels;
use App\Modules\Core\Services\Concerns\ReadsAttendance;
use App\Modules\Core\Support\AttendanceReading;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything the student dashboard puts on screen, gathered in one place.
 *
 * Lives in a service rather than the controller so each panel's data has one
 * named method you can change in isolation -- swap tasks() for a real
 * deadlines table later and no view, route or controller changes.
 *
 * NO CROSS-MODULE REFERENCES. Attendance belongs to app/Modules/Nureen, and
 * this class used to import its Eloquent models directly behind a
 * class_exists() guard -- the one place Core named another folder. It now
 * asks for Core\Contracts\SuppliesAttendance, which Nureen implements and
 * binds. If no module supplies attendance the panel holds its skeleton, the
 * same degradation as before, with the dependency pointing the right way.
 */
class StudentDashboard
{
    /** safely(), remember(), markUnavailable(), unavailable(), withTrend(). */
    use BuildsPanels;

    /** attendanceSource() — the module supplying attendance, or null. */
    use ReadsAttendance;

    public function __construct(protected User $student, protected ?ModuleRegistry $registry = null)
    {
        $this->registry ??= app(ModuleRegistry::class);
    }

    /* ---------------------------------------------------------------
     | The top row: today, candidacy, viva, GA allowance
     |---------------------------------------------------------------*/

    /**
     * The "Today" card: this week (Monday first, matching the mini
     * calendar), today's and tomorrow's calendar events, from every module
     * that implements SuppliesCalendarEvents -- the same source the full
     * Calendar page reads, so the two can never disagree about what is
     * happening today.
     *
     * @return array{week: Collection<int, CarbonInterface>, today: Collection<int, array<string, mixed>>, tomorrowCount: int}
     */
    public function todayCard(): array
    {
        return $this->remember('today', function () {
            $today = now()->startOfDay();

            $events = $this->safely('calendar', fn () => collect(
                $this->registry->calendarEventsFor($this->student, $today, $today->copy()->addDay()->endOfDay())
            ), collect());

            return [
                'week' => collect(range(0, 6))->map(fn ($i) => $today->copy()->startOfWeek()->addDays($i)),
                'today' => $events->filter(fn ($e) => $e['date']->isSameDay($today))->values(),
                'tomorrowCount' => $events->filter(fn ($e) => $e['date']->isSameDay($today->copy()->addDay()))->count(),
            ];
        });
    }

    /** Things "due today", for the welcome banner's subtitle line. */
    public function dueTodayCount(): int
    {
        return $this->todayCard()['today']->count();
    }

    /**
     * The welcome banner's subtitle: today's date, how far into candidacy
     * the student is (when a candidacy is on record -- see
     * candidacyCard()'s monthsIn), and how many things are due today.
     * Never invents a programme type (MSc/PhD): `users.programme` is free
     * text with no reliable split, so that word is left out rather than
     * guessed at.
     */
    public function bannerSubtitle(): string
    {
        $parts = [now()->format('l, j F Y')];

        if ($months = $this->candidacyCard()['monthsIn'] ?? null) {
            $parts[] = "Month {$months}";
        }

        $due = $this->dueTodayCount();
        $parts[] = $due > 0 ? 'You have '.$due.' '.Str::plural('thing', $due).' due today.' : 'Nothing due today.';

        return implode(' · ', $parts);
    }

    /**
     * The "Study candidacy" card, from whichever module owns candidacy
     * expiry (Contracts\SuppliesCandidacyStatus) -- null without one bound,
     * or without a candidacy on record for this student.
     *
     * @return array<string, mixed>|null
     */
    public function candidacyCard(): ?array
    {
        return app()->bound(SuppliesCandidacyStatus::class)
            ? $this->safely('candidacy', fn () => app(SuppliesCandidacyStatus::class)->candidacyStatusFor($this->student), null)
            : null;
    }

    /**
     * The "Viva" card. Nothing supplies this yet -- see
     * Contracts\SuppliesVivaStatus -- so this is always null today; the
     * card renders its own "not scheduled" state rather than Core inventing
     * a date.
     *
     * @return array<string, mixed>|null
     */
    public function vivaCard(): ?array
    {
        return app()->bound(SuppliesVivaStatus::class)
            ? $this->safely('viva', fn () => app(SuppliesVivaStatus::class)->vivaStatusFor($this->student), null)
            : null;
    }

    /**
     * The "GA allowance" card. Nothing supplies this yet -- see
     * Contracts\SuppliesAllowanceStatus -- so this is always null today.
     *
     * @return array<string, mixed>|null
     */
    public function allowanceCard(): ?array
    {
        return app()->bound(SuppliesAllowanceStatus::class)
            ? $this->safely('allowance', fn () => app(SuppliesAllowanceStatus::class)->allowanceStatusFor($this->student), null)
            : null;
    }

    /**
     * "Start a new application": every module a student can self-submit to,
     * from ModuleRegistry::submittable() -- the same source the sidebar
     * builds its tree from, so a teammate's new module appears here the
     * moment they register it, with nothing hardcoded about which modules
     * exist.
     *
     * @return Collection<int, \App\Modules\Core\Contracts\WorkflowModule>
     */
    public function quickLinks(): Collection
    {
        return $this->safely('quickLinks', fn () => collect($this->registry->submittable())
            ->sortBy(fn ($module) => $module->label())
            ->values(), collect());
    }

    /**
     * Saved drafts waiting to be finished, from whichever module supplies
     * them (Contracts\SuppliesDraftCount) -- null without one bound, which
     * hides the "My Drafts" link rather than pointing at a page no drafts
     * of this student's were ever scoped to.
     *
     * @return array{count: int, url: string}|null
     */
    public function drafts(): ?array
    {
        return app()->bound(SuppliesDraftCount::class)
            ? $this->safely('applications', fn () => app(SuppliesDraftCount::class)->draftsFor($this->student), null)
            : null;
    }

    /* ---------------------------------------------------------------
     | The five stat cards
     |---------------------------------------------------------------*/

    /** Latest attendance record, or null if none has been uploaded. */
    public function attendance(): ?AttendanceReading
    {
        // Memoised: three separate panels ask for this in one request.
        return $this->remember('attendance', function () {
            $source = $this->attendanceSource();

            if (! $source) {
                // Not an error -- no module supplies attendance -- but the
                // panel still has nothing real to draw, so hold the skeleton
                // rather than showing an empty state.
                $this->markUnavailable('attendance');

                return null;
            }

            return $this->safely('attendance', fn () => $source->latestFor($this->student), null);
        });
    }

    /**
     * The "Attendance Action" card, worded by the module that supplies
     * attendance (SuppliesAttendance::actionFor()), or null without one.
     *
     * @return array{label: string, value: string, note: string, pill: string, pillTone: string, url: string}|null
     */
    public function attendanceAction(): ?array
    {
        $source = $this->attendanceSource();

        return $source ? $this->safely('attendance', fn () => $source->actionFor($this->student), null) : null;
    }

    public function activeApplications(): int
    {
        return $this->safely('applications', fn () => $this->student->applications()
            ->where('status', Application::STATUS_PENDING)
            ->count(), 0);
    }

    /**
     * The earliest expected decision date across the student's pending
     * applications, from modules that keep a charter
     * (Contracts\SuppliesDecisionDates), or null when none promises one.
     */
    public function nextDecisionDate(): ?CarbonInterface
    {
        return $this->safely('applications', fn () => $this->student->applications()
            ->where('status', Application::STATUS_PENDING)
            ->with('history')
            ->get()
            ->map(fn (Application $application) => ($module = $application->module()) instanceof SuppliesDecisionDates
                ? $module->expectedDecisionDate($application)
                : null)
            ->filter()
            ->sort()
            ->first(), null);
    }

    public function unreadNotifications(): int
    {
        return $this->safely('notifications', fn () => $this->student->unreadNotifications()->count(), 0);
    }

    /* ---------------------------------------------------------------
     | The panels
     |---------------------------------------------------------------*/

    /** @return Collection<int, Application> */
    public function applications(int $limit = 5): Collection
    {
        return $this->safely('applications', fn () => $this->student->applications()
            ->with('history')
            ->latest('submitted_at')
            ->limit($limit)
            ->get(), collect());
    }

    /** @return Collection<int, \Illuminate\Notifications\DatabaseNotification> */
    public function notifications(int $limit = 4): Collection
    {
        return $this->safely('notifications', fn () => $this->student->notifications()->latest()->limit($limit)->get(), collect());
    }

    /**
     * "Upcoming Tasks" — things genuinely waiting on the student.
     *
     * DERIVED FROM REAL STATE, not a tasks table: this portal has no
     * deadlines model, so rather than invent due dates these are the two
     * situations where the student actually has to do something next:
     *
     *   1. An application was rejected and needs looking at / resubmitting.
     *   2. Attendance is flagged at-risk and no appeal is open yet.
     *
     * When a real deadlines/tasks table arrives (RPD reminders will want
     * one), replace the body of this method and the panel keeps working.
     *
     * @return Collection<int, array{title: string, subtitle: string, meta: string, urgency: string, url: ?string}>
     */
    public function tasks(int $limit = 4): Collection
    {
        return $this->safely('tasks', fn () => $this->buildTasks($limit), collect());
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function buildTasks(int $limit): Collection
    {
        $tasks = collect();

        foreach ($this->student->applications()->where('status', Application::STATUS_REJECTED)->latest('updated_at')->limit($limit)->get() as $application) {
            $decided = $application->rejection();

            $tasks->push([
                'title' => 'Review and resubmit',
                'subtitle' => $application->module()->label().' · '.$application->reference(),
                'meta' => $decided ? 'Rejected '.$decided->created_at->format('j M Y') : 'Not approved',
                'urgency' => 'critical',
                'url' => route('applications.show', $application),
            ]);
        }

        $attendance = $this->attendance();

        if ($attendance?->at_risk && ! $this->hasOpenAttendanceAppeal()) {
            $tasks->push([
                'title' => 'File an attendance appeal',
                'subtitle' => 'Attendance is below the 80% threshold',
                'meta' => 'Period ending '.$attendance->period_end->format('j M Y'),
                'urgency' => 'warn',
                'url' => route('attendance-appeal.create'),
            ]);
        }

        return $tasks->take($limit)->values();
    }

    public function upcomingTaskCount(): int
    {
        return $this->tasks(99)->count();
    }

    /* ---------------------------------------------------------------
     | Helpers
     |---------------------------------------------------------------*/

    /**
     * What the gauge reads before anything has been uploaded.
     *
     * A student with no sessions on file has not missed one either: the dial
     * starts full and comes down as absences are recorded, rather than
     * opening at zero and telling a first-week student they are critical.
     * Deliberately NOT the same as the panel having no attendance source at
     * all -- that keeps its skeleton, because then the figure is unknown
     * rather than untouched.
     */
    public const STARTING_PERCENTAGE = 100.0;

    /**
     * Attendance bands, shared by the gauge and its legend so the two can
     * never disagree about where "Good" stops and "Warning" starts.
     *
     * @return array<int, array{label: string, tone: string, from: float}>
     */
    public static function attendanceBands(): array
    {
        return [
            ['label' => 'Good (≥ 85%)', 'tone' => 'good', 'from' => 85.0],
            ['label' => 'Warning (75% – 84%)', 'tone' => 'warn', 'from' => 75.0],
            ['label' => 'Critical (< 75%)', 'tone' => 'critical', 'from' => 0.0],
        ];
    }

    public static function toneFor(?float $percentage): string
    {
        if ($percentage === null) {
            return 'none';
        }

        foreach (self::attendanceBands() as $band) {
            if ($percentage >= $band['from']) {
                return $band['tone'];
            }
        }

        return 'critical';
    }

    protected function hasOpenAttendanceAppeal(): bool
    {
        return $this->student->applications()
            ->where('module_type', 'attendance_appeal')
            ->where('status', Application::STATUS_PENDING)
            ->exists();
    }

}
