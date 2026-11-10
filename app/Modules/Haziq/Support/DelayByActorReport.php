<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GraApplicationDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How long each actor actually took once a GRA/GA application reached
 * them. Reads entirely off the shared `approval_history` table
 * WorkflowEngine already writes for every module -- adds no table or
 * job of its own, same "reporting view over data captured upstream"
 * shape as Allowance Eligibility (see AllowanceController's docblock).
 *
 * Confirmed explicitly 2026-11-06, then refined the same day: "tambah
 * filter jugak... boleh filter by role... individual daripada each
 * role... ga and gra lain pulak... adakan untuk 2 2... mesti boleh
 * buat prediction base on general, specific role, or individual."
 * GRA and GA genuinely have different approver chains (GA alone has
 * Research Centre), so the module filter changes which roles are even
 * selectable -- see rolesFor().
 *
 * "Received" for the first stage is the application's own
 * `submitted_at`; for every stage after, it is the instant the
 * previous stage was decided -- the real handoff moment, not some
 * separate "opened the page" event nothing logs.
 *
 * `predict()` is a historical average, not a trained model -- with as
 * few real decisions as this environment has, anything fancier would
 * be fitting noise. It is the correct baseline estimate for a new
 * application's delay at a given scope, and says so plainly rather
 * than dressing a mean up as more than it is.
 */
class DelayByActorReport
{
    /** Below this many decisions, a prediction is noted as unreliable rather than hidden. */
    public const RELIABLE_SAMPLE = 3;

    /**
     * The four scopes this report can be narrowed to -- confirmed
     * explicitly 2026-11-10: a new appointment and its own extension are
     * different enough in shape (an extension skips GA's Research
     * Centre stage entirely, for one) that averaging them together
     * would blur what this report predicts for, so there is
     * deliberately no "both" choice -- "filter for gra and ga
     * application theres no such thing as both, kene choose either
     * one... either one application for ga or gra or extention for ga
     * or gra." GRA's own 'extension' rows share gra_application's
     * module_type with 'new', so that one additionally splits on
     * GraApplicationDetail::application_type; GA has no such split (see
     * GaApplicationWorkflow's own docblock) and GA Extension is simply
     * Nureen's own, separate module_type.
     *
     * @var array<string, array{module_type: string, application_type: ?string, label: string}>
     */
    public const SCOPES = [
        'gra_application' => ['module_type' => 'gra_application', 'application_type' => 'new', 'label' => 'GRA Application'],
        'gra_extension' => ['module_type' => 'gra_application', 'application_type' => 'extension', 'label' => 'GRA Extension'],
        'ga_application' => ['module_type' => 'ga_application', 'application_type' => null, 'label' => 'GA Application'],
        'ga_extension' => ['module_type' => 'ga_extension', 'application_type' => null, 'label' => 'GA Extension'],
    ];

    /**
     * The only roles an individual-level breakdown is ever meaningful for
     * -- confirmed explicitly 2026-11-10: "role yang general siapa siapa
     * leh verify cam admin, cgs, senior director taleh nak narrow down
     * analysis siapa banyak cause delay... tapi supervisor and research
     * centre... each student memang ada specific supervisor and research
     * centre." Admin/CGS/Senior Director are general: any member can
     * pick up any application, so "this specific person is slow" isn't a
     * usable prediction for any one student's NEXT application -- a
     * different member could just as easily get it. Supervisor (every
     * scope) and Research Centre (ga_application only, the only chain
     * that has it) are the opposite: a given student's own supervisor
     * and department Research Centre are who will keep handling their
     * applications, so a person-level number there is actually
     * predictive. Role-level comparison (byRole(), the bottleneck card)
     * is untouched by this -- only the individual drill-down is scoped.
     */
    public const NARROWABLE_ROLES = [Role::SUPERVISOR, Roles::RESEARCH_CENTRE];

    /**
     * One row per decision ever made, for exactly one of the four SCOPES
     * above -- there is no "all of them" option. Only applications that
     * have actually reached a final outcome (approved or rejected) are
     * counted -- confirmed explicitly 2026-11-10: "memang student dah
     * approved or rejected tak kisah, yang penting sampai 2 outcome tu,
     * pending stage tak kira." A still-pending application's history so
     * far is a partial, unfinished journey; mixing that into the same
     * average as applications that actually finished understates how
     * long the REST of a real journey takes, which an application still
     * early in its own chain simply hasn't gotten to yet. One finished
     * application is plenty -- it's completeness that matters here, not
     * volume.
     */
    public static function rows(string $scope): Collection
    {
        $config = self::SCOPES[$scope] ?? abort(404, "Unknown report scope: {$scope}");

        $applications = Application::where('module_type', $config['module_type'])
            ->whereIn('status', [Application::STATUS_APPROVED, Application::STATUS_REJECTED])
            ->when(
                $config['application_type'] === 'extension',
                fn ($q) => $q->whereIn('id', GraApplicationDetail::where('application_type', 'extension')->pluck('application_id')),
                fn ($q) => $q->when(
                    $config['application_type'] === 'new',
                    fn ($q) => $q->whereNotIn('id', GraApplicationDetail::where('application_type', 'extension')->pluck('application_id'))
                )
            )
            ->get(['id', 'module_type', 'student_id', 'submitted_at']);

        $historyByApplication = ApprovalHistory::whereIn('application_id', $applications->pluck('id'))
            ->with('approver:id,name,role')
            ->orderBy('application_id')
            ->orderBy('created_at')
            ->get()
            ->groupBy('application_id');

        // admin_verify can no longer even be decided while a required
        // document is still missing (see GraApplicationController and
        // GaApplicationController's own decide() guards), so the time a
        // student spent completing documents is stuck inside Admin's own
        // received-to-decided window whether Admin was prompt or not.
        // Confirmed explicitly 2026-11-10: "delay by actor analysis taleh
        // count situasi ni delay pada admin." Only 'new' applications are
        // gated that way (extensions were never required to attach these
        // documents at all), hence the scope check rather than reading it
        // off every row.
        $gatedOnDocuments = in_array($scope, ['gra_application', 'ga_application'], true);

        $documentCompleteAt = $gatedOnDocuments
            ? self::documentCompleteTimestamps($applications)
            : collect();

        $rows = collect();

        foreach ($applications as $application) {
            $receivedAt = $application->submitted_at;

            foreach ($historyByApplication->get($application->id, collect()) as $entry) {
                $decidedAt = $entry->created_at;
                $effectiveReceivedAt = $receivedAt;

                if ($entry->stage_key === 'admin_verify' && $receivedAt) {
                    $completedAt = $documentCompleteAt->get($application->id);

                    if ($completedAt && $completedAt->gt($receivedAt)) {
                        $effectiveReceivedAt = $completedAt;
                    }
                }

                if ($effectiveReceivedAt && $decidedAt) {
                    $rows->push([
                        'application_id' => $application->id,
                        'application_ref' => $application->reference(),
                        'module_type' => $application->module_type,
                        'stage_key' => $entry->stage_key,
                        'stage_label' => $entry->stage_label,
                        'decision' => $entry->decision,
                        'approver_id' => $entry->approver_id,
                        'approver_name' => $entry->approver?->name ?? 'Unknown',
                        'approver_role' => $entry->approver?->role,
                        'received_at' => $effectiveReceivedAt,
                        'decided_at' => $decidedAt,
                        'delay_hours' => $effectiveReceivedAt->floatDiffInHours($decidedAt),
                    ]);
                }

                // The next stage's clock starts the moment this one decided --
                // the handoff is instantaneous by definition, nothing else
                // records when a later actor actually opened the page. Uses
                // the real decided_at, never the adjusted one above -- only
                // Admin's own measured delay is adjusted, not the chain.
                $receivedAt = $decidedAt;
            }
        }

        return $rows;
    }

    /**
     * The instant each application's last still-required document was
     * uploaded, keyed by application id -- when that is later than the
     * application's own submission, that gap was the student completing
     * paperwork, not Admin sitting on it. Omits any application with no
     * required documents to begin with (nothing to have waited on).
     *
     * @param Collection<int, Application> $applications
     * @return Collection<int, Carbon>
     */
    protected static function documentCompleteTimestamps(Collection $applications): Collection
    {
        $byModule = $applications->groupBy('module_type');
        $result = collect();

        // Built up with union(), not flatMap()/merge() -- both of those
        // collapse integer keys positionally and would silently throw
        // away every application_id key this is keyed by.
        foreach ($byModule as $moduleType => $apps) {
            // ApplicationDocument::doc_type stores the document's LABEL
            // (e.g. "Resume/CV"), not requiredDocuments()'s own array key
            // -- see DocumentCompleteness::missingFor(), which compares
            // against labels the same way.
            $requiredLabels = array_values(DocumentCompleteness::requiredDocuments($moduleType));

            if (empty($requiredLabels)) {
                continue;
            }

            $timestamps = ApplicationDocument::whereIn('application_id', $apps->pluck('id'))
                ->whereIn('doc_type', $requiredLabels)
                ->selectRaw('application_id, max(created_at) as completed_at')
                ->groupBy('application_id')
                ->pluck('completed_at', 'application_id')
                ->map(fn ($value) => Carbon::parse($value));

            $result = $result->union($timestamps);
        }

        return $result;
    }

    /**
     * Every role this scope's chain can reach, for the Role filter's
     * options -- "senang citer boleh choose either one [module], role
     * je leh filter by spesific or all" (the Role filter itself keeps
     * its own "any role" choice; only the module/scope choice above is
     * mandatory). GRA's two scopes share one chain (same stage keys,
     * same roles, whether 'new' or 'extension'), so both resolve to the
     * same role list via gra_application's own registry entry.
     */
    public static function rolesFor(string $scope): Collection
    {
        $moduleType = self::SCOPES[$scope]['module_type'] ?? abort(404, "Unknown report scope: {$scope}");

        return collect(app(ModuleRegistry::class)->get($moduleType)->stages(null))
            ->pluck('role')
            ->unique()
            ->values();
    }

    /**
     * rolesFor() narrowed to NARROWABLE_ROLES -- what the Role filter
     * actually offers to select, and the only roles the "By individual
     * actor" table ever breaks out a person for. Naturally comes back
     * empty of Research Centre for every scope except ga_application,
     * since no other chain reaches that role at all.
     */
    public static function narrowableRolesFor(string $scope): Collection
    {
        return self::rolesFor($scope)->intersect(self::NARROWABLE_ROLES)->values();
    }

    /** Every user who holds a role this scope's chain can reach, optionally narrowed to one role. */
    public static function actorsFor(string $scope, ?string $role = null): Collection
    {
        $roles = $role ? [$role] : self::rolesFor($scope)->all();

        return User::whereIn('role', $roles)->orderBy('name')->get(['id', 'name', 'role']);
    }

    /**
     * The one prediction figure for a scope: a plain historical average
     * in days, the count it's drawn from, and whether that count is
     * large enough to trust. Null rows() means "no history for this
     * scope at all" rather than a misleading zero. `$allRows`, when
     * given, is the complete (role-unfiltered) row set for the same
     * module filter, used only to work out `share_percent` -- how much
     * of an application's *total* turnaround this scope accounts for,
     * the figure "which role make delay for application" actually
     * asks for, since a role that is merely slow in isolation might
     * still be a small share of the whole wait.
     */
    public static function predict(Collection $rows, string $label, ?Collection $allRows = null): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $avgHours = $rows->avg('delay_hours');

        return [
            'label' => $label,
            'decisions' => $rows->count(),
            'avg_hours' => round($avgHours, 1),
            'avg_days' => round($avgHours / 24, 1),
            'reliable' => $rows->count() >= self::RELIABLE_SAMPLE,
            'share_percent' => $allRows ? self::avgSharePercent($rows, $allRows) : null,
        ];
    }

    /**
     * How much of an application's *total* turnaround (submission to
     * its latest decision so far) a set of rows accounts for, averaged
     * across the applications those rows actually touch. Null for an
     * application whose total is 0 (nothing to divide by yet).
     */
    protected static function avgSharePercent(Collection $rows, Collection $allRows): ?float
    {
        $totals = self::applicationTotals($allRows);

        $shares = $rows->map(function (array $row) use ($totals) {
            $total = $totals->get($row['application_id']);

            return $total > 0 ? $row['delay_hours'] / $total * 100 : null;
        })->filter(fn ($v) => $v !== null);

        return $shares->isNotEmpty() ? round($shares->avg()) : null;
    }

    /** Each application's full turnaround so far (sum of every stage's delay_hours), keyed by application id. */
    public static function applicationTotals(Collection $allRows): Collection
    {
        return $allRows->groupBy('application_id')->map(fn (Collection $appRows) => $appRows->sum('delay_hours'));
    }

    /**
     * The chart's own bars, one drill-down level driven by the
     * filters -- confirmed explicitly 2026-11-06: "general means sum
     * up every person in each role, still showing every role for
     * comparison... when filter by role, shows every individual...
     * individual specifically show that one specific individual only
     * ...sebab every role kan ramai." Refined the same day: "which
     * role make delay for application kene ade" -- each bar also
     * carries `share_percent`, its average share of an application's
     * whole turnaround, so the chart answers "which role/individual
     * actually drives the delay" and not just "which one is slow in
     * isolation."
     *
     * No role selected: one bar per role, each already an average
     * across everyone who has held it -- the comparison view. A role
     * selected with no individual: one bar per person who has held
     * that role, since the role-level number alone hides how spread
     * out its own members are. Both role and individual selected:
     * that one person's bar alone.
     */
    public static function chartBars(Collection $rows, ?string $role, ?int $actorId): Collection
    {
        if ($role && $actorId) {
            $actorRows = $rows->where('approver_role', $role)->where('approver_id', $actorId);
            $bar = self::predict($actorRows, $actorRows->first()['approver_name'] ?? 'Selected actor', $rows);

            return $bar ? collect([$bar]) : collect();
        }

        if ($role) {
            return self::byActor($rows->where('approver_role', $role), $rows)
                ->map(fn (array $a) => [
                    'label' => $a['approver_name'],
                    'decisions' => $a['decisions'],
                    'avg_hours' => $a['avg_hours'],
                    'avg_days' => $a['avg_days'],
                    'reliable' => $a['decisions'] >= self::RELIABLE_SAMPLE,
                    'share_percent' => $a['share_percent'],
                ])
                ->values();
        }

        return self::byRole($rows, $rows)
            ->map(fn (array $r) => [
                'label' => Role::label($r['role']),
                'decisions' => $r['decisions'],
                'avg_hours' => $r['avg_hours'],
                'avg_days' => $r['avg_days'],
                'reliable' => $r['decisions'] >= self::RELIABLE_SAMPLE,
                'share_percent' => $r['share_percent'],
            ])
            ->values();
    }

    /**
     * One row per role, averaged across every member who has ever
     * decided a stage in the given rows. Keyed by role. `$allRows`
     * (role-unfiltered, same module scope) drives `share_percent`;
     * omit it only when the caller has no use for that figure.
     */
    public static function byRole(Collection $rows, ?Collection $allRows = null): Collection
    {
        return $rows->groupBy('approver_role')
            ->map(fn (Collection $roleRows) => [
                'role' => $roleRows->first()['approver_role'],
                'decisions' => $roleRows->count(),
                'actors' => $roleRows->pluck('approver_id')->unique()->count(),
                'avg_hours' => round($roleRows->avg('delay_hours'), 1),
                'avg_days' => round($roleRows->avg('delay_hours') / 24, 1),
                'share_percent' => $allRows ? self::avgSharePercent($roleRows, $allRows) : null,
            ])
            ->sortByDesc('avg_hours');
    }

    /**
     * The role with the single highest average share of an
     * application's total turnaround -- the direct answer to "which
     * role make delay for application." Falls back to the slowest
     * role in isolation (`avg_hours`) when no role has a usable share
     * yet (too little data to divide by). Null with nothing decided
     * at all.
     */
    public static function bottleneckRole(Collection $byRole): ?array
    {
        if ($byRole->isEmpty()) {
            return null;
        }

        $ranked = $byRole->whereNotNull('share_percent')->sortByDesc('share_percent');

        return $ranked->first() ?? $byRole->sortByDesc('avg_hours')->first();
    }

    /**
     * One row per individual actor. `vs_role_percent` is how much
     * slower (positive) or faster (negative) this person runs compared
     * to everyone else who has ever held their role in the given rows.
     * Reads as 0% whenever a role currently has only one member, since
     * there is nobody else in the role to compare against yet.
     * `$allRows` drives `share_percent`, same as byRole().
     */
    public static function byActor(Collection $rows, ?Collection $allRows = null): Collection
    {
        // Raw, unrounded per-role averages -- comparing against byRole()'s
        // own *rounded* avg_hours would make a single-member role read as
        // slower or faster than itself purely from rounding noise, since
        // that role's "average" is nothing but this one person's figure.
        $rawRoleAverages = $rows->groupBy('approver_role')->map(fn (Collection $roleRows) => $roleRows->avg('delay_hours'));

        return $rows->groupBy('approver_id')
            ->map(function (Collection $actorRows) use ($rawRoleAverages, $allRows) {
                $first = $actorRows->first();
                $avgHours = $actorRows->avg('delay_hours');
                $role = $first['approver_role'];
                $roleAvgHours = $rawRoleAverages->get($role);

                return [
                    'approver_id' => $first['approver_id'],
                    'approver_name' => $first['approver_name'],
                    'role' => $role,
                    'decisions' => $actorRows->count(),
                    'avg_hours' => round($avgHours, 1),
                    'avg_days' => round($avgHours / 24, 1),
                    'vs_role_percent' => ($roleAvgHours && $roleAvgHours > 0)
                        ? (int) round((($avgHours - $roleAvgHours) / $roleAvgHours) * 100)
                        : 0,
                    'share_percent' => $allRows ? self::avgSharePercent($actorRows, $allRows) : null,
                ];
            })
            ->sortByDesc('avg_hours')
            ->values();
    }
}
