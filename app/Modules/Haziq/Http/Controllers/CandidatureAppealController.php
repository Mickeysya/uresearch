<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\CandidatureAppeal;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Notifications\CandidatureAppealApproved;
use App\Modules\Haziq\Notifications\CandidatureAppealRejected;
use App\Modules\Haziq\Notifications\CandidatureAppealSubmitted;
use App\Modules\Haziq\Support\StageGateEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * A student appealing their own Stage Gate termination on one GRA/GA
 * application — reinstates that same application, never a new submission.
 * An earlier mechanism required resubmitting the whole GRA form with an
 * extra letter when reapplying after a termination
 * (GraApplicationController used to carry a TERMINATION_APPEAL_FIELD for
 * this); removed entirely 2026-10-15 in favour of this dedicated action —
 * confirmed explicitly: "die consider new action. bukan keluar kat sini."
 * Only a Senior Director may decide; Admin/CGS/Supervisor are notified and
 * can see the queue, same audience as every other Stage Gate consequence,
 * but cannot act on it.
 */
class CandidatureAppealController extends Controller
{
    public function create(Request $request, Application $application): View
    {
        $this->authorizeStudentAppeal($request, $application);

        return view('haziq::candidature_appeal.create', [
            'application' => $application,
            'pending' => $this->pendingAppeal($application),
        ]);
    }

    public function store(Request $request, Application $application, DocumentStore $documents): RedirectResponse
    {
        $this->authorizeStudentAppeal($request, $application);

        if ($this->pendingAppeal($application)) {
            return back()->with('error', 'An appeal for this application is already pending a Senior Director decision.');
        }

        $request->validate([
            'appeal_letter' => DocumentStore::rules(true),
        ]);

        $appeal = CandidatureAppeal::create([
            'application_id' => $application->id,
            'student_id' => $request->user()->id,
            'status' => 'pending',
        ]);

        $this->replaceDocument($application, $request->file('appeal_letter'), 'Candidature Appeal Letter', $documents);

        // Its own log_name — a distinct action from the application's own
        // decide() (logged under 'workflow') and from Mark Complete
        // (logged under 'stage_gate'). Confirmed explicitly 2026-10-15:
        // this is a different action, not a resubmission of either of
        // those, and the admin audit log (Core's Activity-backed
        // admin.audit) picks up any log_name automatically — nothing to
        // register there.
        activity('candidature_appeal')
            ->causedBy($request->user())
            ->performedOn($appeal)
            ->withProperties([
                'action' => 'submitted',
                'application_id' => $application->id,
                'student_id' => $request->user()->id,
            ])
            ->log('Candidature appeal submitted');

        $this->stakeholders($application)->each(
            fn (User $u) => $u->notify(new CandidatureAppealSubmitted($appeal, $application))
        );

        return redirect()->route('stage-gate.index')
            ->with('status', 'Your appeal has been submitted. A Senior Director will review it.');
    }

    public function queue(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isCgs() || $user->role === Role::SUPERVISOR, 403);

        $query = CandidatureAppeal::query()->with(['application.student', 'student']);

        if ($user->role === Role::SUPERVISOR) {
            $query->whereHas('application.student', fn ($q) => $q->where('supervisor_id', $user->id));
        }

        return view('haziq::candidature_appeal.queue', [
            'appeals' => $query->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
                ->latest('id')
                ->paginate(25),
            'canDecide' => $user->role === Role::SENIOR_DIRECTOR_CGS,
        ]);
    }

    public function decide(Request $request, CandidatureAppeal $appeal, StageGateEngine $engine): RedirectResponse
    {
        abort_unless($request->user()->role === Role::SENIOR_DIRECTOR_CGS, 403, 'Only a Senior Director can decide a candidature appeal.');
        abort_unless($appeal->isPending(), 404, 'This appeal has already been decided.');

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000', 'required_if:decision,reject'],
        ], [
            'remarks.required_if' => 'A reason is required to reject an appeal.',
        ]);

        $appeal->application->loadMissing('student');

        $appeal->update([
            'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_notes' => $data['remarks'] ?? null,
        ]);

        activity('candidature_appeal')
            ->causedBy($request->user())
            ->performedOn($appeal)
            ->withProperties([
                'action' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'application_id' => $appeal->application_id,
                'student_id' => $appeal->student_id,
            ])
            ->log($data['decision'] === 'approve' ? 'Candidature appeal approved' : 'Candidature appeal rejected');

        if ($data['decision'] === 'approve') {
            $engine->reinstateCandidature($appeal->application);

            // Confirmed explicitly 2026-10-28: "bulan kene terminated
            // takkan dapat allowance" — a terminated month is never
            // back-paid, so reinstating candidature never rewrites any
            // past month's log row. The one month reinstatement happens
            // in resolves immediately on its own, whatever day it lands
            // on — see StageGateEngine::resumeReinstatementMonth()'s own
            // docblock for how this settled, after two earlier, more
            // complicated versions the same day.
            $engine->resumeReinstatementMonth($appeal->application);

            // Student + everyone else — confirmed explicitly 2026-10-22,
            // appeal approved is in the same "semua semua" stakeholder
            // list as the reminder/withheld/terminated emails: "student,
            // admin, cgs, supervisor, senior director ni stakeholder
            // die." Previously student-only.
            $appeal->application->student?->notify(new CandidatureAppealApproved($appeal->application, forStudent: true));
            $this->stakeholders($appeal->application)->each(
                fn (User $u) => $u->notify(new CandidatureAppealApproved($appeal->application, forStudent: false))
            );

            return back()->with('status', 'Appeal approved — the candidature is active again.');
        }

        $appeal->application->student?->notify(new CandidatureAppealRejected($appeal->application, $data['remarks']));

        return back()->with('status', 'Appeal rejected.');
    }

    /** Admin + CGS team + the application's own student's assigned Supervisor — shared by the submitted/approved notifications above. */
    protected function stakeholders(Application $application): Collection
    {
        $application->loadMissing('student.supervisor');

        $recipients = User::where('role', Role::ADMIN)->get()
            ->merge(User::whereIn('role', Role::cgsTeam())->get());

        if ($supervisor = $application->student?->supervisor) {
            $recipients->push($supervisor);
        }

        return $recipients->unique('id');
    }

    protected function authorizeStudentAppeal(Request $request, Application $application): void
    {
        abort_unless($application->student_id === $request->user()->id, 403);
        abort_unless(in_array($application->module_type, ['gra_application', 'ga_application'], true), 404);
        abort_unless($this->detailFor($application)?->isTerminated(), 404, 'This application is not terminated.');
    }

    protected function detailFor(Application $application): GraApplicationDetail|GaApplicationDetail|null
    {
        return $application->module_type === 'gra_application'
            ? GraApplicationDetail::where('application_id', $application->id)->first()
            : GaApplicationDetail::where('application_id', $application->id)->first();
    }

    protected function pendingAppeal(Application $application): ?CandidatureAppeal
    {
        return CandidatureAppeal::where('application_id', $application->id)
            ->where('status', 'pending')
            ->first();
    }

    /**
     * Same fix as Concerns\HandlesApplicationCorrections::replaceDocument()
     * and for the same reason: DocumentStore::attach() is always just a
     * create(), so a student terminated more than once (a later Stage
     * Gate, after an earlier appeal was already decided) appealing again
     * was piling up another "Candidature Appeal Letter" row on top of
     * every earlier one instead of replacing it — confirmed explicitly
     * 2026-11-11 from the resulting document list showing the same label
     * repeated many times over. Not pulled from that trait directly: this
     * controller implements none of its other abstract methods (it has no
     * "sections" or "stage" concept of its own to flag against).
     */
    protected function replaceDocument(Application $application, UploadedFile $file, string $docType, DocumentStore $documents): void
    {
        ApplicationDocument::where('application_id', $application->id)
            ->where('doc_type', $docType)
            ->get()
            ->each(function (ApplicationDocument $old) {
                Storage::disk('local')->delete($old->path);
                $old->delete();
            });

        $documents->attach($application, $file, $docType);
    }
}
