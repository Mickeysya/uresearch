<?php

namespace App\Modules\Haziq\Workflows;

use App\Modules\Core\Contracts\ProvidesLinks;
use App\Modules\Core\Contracts\SuppliesCalendarEvents;
use App\Modules\Core\Contracts\WorkflowModule;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Support\Roles;
use Carbon\CarbonInterface;

/**
 * GA Application. Same profile/document shape as GRA (Section D/E/F via
 * the shared gra_ga_student_profiles) but a different chain and a
 * different life after approval — confirmed explicitly 2026-11-03: "ga is
 * different from gra."
 *
 * Five stages, same shape as GRA's own chain with Research Centre's
 * interview inserted between Admin and CGS: Admin verifies the paperwork
 * (never rejects outright, same as GRA — see
 * GaApplicationController::decide()), Research Centre conducts the
 * interview and records Pass/Fail with a report attached (a Fail rejects
 * outright, right here — CGS never sees it), then CGS Staff / Supervisor
 * / Senior Director each approve or reject once, same as GRA.
 *
 * Unlike GRA, approval never generates a Stage Gate schedule or touches
 * allowance tracking at all — "after approve takdelah stage gate or
 * allowance bagai bagai tu, die just application approve" — a GA
 * appointment is just the approved record plus its offer letters.
 *
 * GA extension is out of scope for this module entirely — that stays
 * Nureen's ga_extension.
 */
class GaApplicationWorkflow implements WorkflowModule, ProvidesLinks, SuppliesCalendarEvents
{
    /**
     * "GA Applicants" (the full index — every applicant, the Pending by
     * Stage bottleneck chart — not just this viewer's own queue) had no
     * sidebar entry anywhere, unlike GraApplicationWorkflow's own
     * identical "GRA Applicants" link -- a page that works once you know
     * the URL, but nothing in Actions pointed at it. Confirmed explicitly
     * 2026-11-10: "kat actions nampak gra applicants je, patut leh
     * nampak jugak ga applicants." Research Centre included alongside
     * Admin/CGS/Supervisor since it is a real approver on this chain,
     * unlike GRA's.
     */
    public function links(User $user): array
    {
        if ($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR || $user->role === Roles::RESEARCH_CENTRE) {
            $links = [['label' => 'GA Applicants', 'route' => 'ga-application.index']];

            // No separate "GA Allowance Eligibility" link for
            // Admin/CGS/Supervisor -- they already get the shared
            // "Allowance Eligibility" link from
            // GraApplicationWorkflow::links(), which now carries a module
            // filter (GRA/GA) on the one screen. Confirmed explicitly
            // 2026-11-10, revising the same day's earlier "navigation bar
            // asing" version: "ga allowoance eleigability masuk je dekat
            // page allowance eligability... apply for admin dah ke atas."
            // The export stays two separate files/routes regardless (see
            // AllowanceController::index()'s own docblock) -- Research
            // Centre still gets neither, same as before.

            // Admin/CGS/Supervisor already get the general, pick-any-scope
            // Delay by Actor link from GraApplicationWorkflow::links() --
            // Research Centre gets its own, locked to this module.
            // Confirmed explicitly 2026-11-10: "boleh je tengok delay by
            // actor tapi yang role die related sahaja ah which is ga
            // application." See DelayReportController::index().
            if ($user->role === Roles::RESEARCH_CENTRE) {
                $links[] = ['label' => 'Delay by Actor', 'route' => 'delay-report.index'];
            }

            return $links;
        }

        // "My GA Application" -- same gap as "GA Applicants" above, same
        // fix: GraApplicationWorkflow::links() has had the GRA equivalent
        // ("My GRA Application") all along, this module just never had
        // its own. Without it, a student's only way back to a submitted
        // GA application was knowing the URL. Confirmed explicitly
        // 2026-11-10. GA has no application_type of its own to filter on
        // (see this class's own docblock), unlike GRA's 'new' vs
        // 'extension' split.
        if ($user->isStudent()) {
            $mine = Application::where('student_id', $user->id)
                ->where('module_type', 'ga_application')
                ->where('status', '!=', Application::STATUS_DRAFT)
                ->latest('id')
                ->first();

            if (! $mine) {
                return [];
            }

            $links = [['label' => 'My GA Application', 'route' => 'ga-application.mine', 'params' => ['application' => $mine->id]]];

            // Only once actually approved -- own GA Allowance Eligibility
            // link, own name, confirmed explicitly 2026-11-10. The
            // controller itself scopes a student to their own row only,
            // same split as GRA's "My Allowance" above.
            if (Application::where('student_id', $user->id)->where('module_type', 'ga_application')->where('status', Application::STATUS_APPROVED)->exists()) {
                $links[] = ['label' => 'My GA Allowance', 'route' => 'ga-allowance.index'];
            }

            return $links;
        }

        return [];
    }

    public function key(): string
    {
        return 'ga_application';
    }

    public function label(): string
    {
        return 'GA Application';
    }

    public function stages(?Application $application = null): array
    {
        return [
            new Stage(
                key: 'admin_verify',
                label: 'Admin',
                role: Role::ADMIN,
                decision: 'verified',
                queueTitle: 'Pending Document Verification',
            ),
            new Stage(
                key: 'research_centre_interview',
                label: 'Research Centre',
                role: Roles::RESEARCH_CENTRE,
                decision: 'interviewed',
                queueTitle: 'Pending Interview',
            ),
            new Stage(
                key: 'cgs_review',
                label: 'CGS Staff',
                role: Role::NON_EXEC_CGS,
                decision: 'reviewed',
                queueTitle: 'Pending CGS Approval',
            ),
            new Stage(
                key: 'supervisor',
                label: 'Supervisor',
                role: Role::SUPERVISOR,
                decision: 'endorsed',
                queueTitle: 'Pending My Review',
            ),
            new Stage(
                key: 'senior_director',
                label: 'Senior Director CGS',
                role: Role::SENIOR_DIRECTOR_CGS,
                decision: 'approved',
                queueTitle: 'Final Approval',
            ),
        ];
    }

    public function summary(Application $application): string
    {
        $detail = GaApplicationDetail::where('application_id', $application->id)->first();

        if (! $detail) {
            return 'GA application';
        }

        return 'GA Application — '.($detail->department_unit ?? $detail->purpose ?? 'General');
    }

    public function createRoute(): ?string
    {
        return 'ga-application.create';
    }

    public function queueRoute(): string
    {
        return 'ga-application.queue';
    }

    /**
     * The interview date Research Centre sets via scheduleInterview() --
     * confirmed explicitly 2026-11-10: "die yang set interview bile and
     * keluar noti, and mark terus kat calender student." GaInterviewScheduled
     * already emails it; this is the same date landing on the Calendar page
     * too, same "every date lives in a teammate's table" shape as every
     * other SuppliesCalendarEvents implementer. Shown to the student (their
     * own interview) and to Research Centre (whoever is actually conducting
     * it, scoped to their own department, same as scheduleInterview()/
     * decide() themselves) -- nobody else's business until a decision is
     * actually made on it.
     */
    public function calendarEvents(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! in_array($user->role, [Role::STUDENT, Roles::RESEARCH_CENTRE], true)) {
            return [];
        }

        $details = GaApplicationDetail::with('application.student')
            ->whereNotNull('interview_date')
            ->whereBetween('interview_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(
                $user->role === Role::STUDENT,
                fn ($q) => $q->whereHas('application', fn ($a) => $a->where('student_id', $user->id)),
                fn ($q) => $q->whereHas('application.student', fn ($s) => $s->where('department', $user->department)),
            )
            ->get();

        return $details->map(fn (GaApplicationDetail $detail) => [
            'date' => $detail->interview_date,
            'title' => $user->role === Role::STUDENT
                ? 'Your GA interview'
                : 'GA interview: '.$detail->application->student?->name,
            'tone' => 'critical',
            'meta' => $detail->interview_date->format('g:i a').' to '.($detail->interview_ends_at?->format('g:i a') ?? '?'),
            'url' => $user->role === Role::STUDENT
                ? route('ga-application.mine', $detail->application_id)
                : route('ga-application.show', $detail->application_id),
        ])->all();
    }
}
