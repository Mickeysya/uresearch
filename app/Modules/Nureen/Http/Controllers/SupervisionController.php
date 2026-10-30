<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Stage;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Notifications\AppointmentLetterIssued;
use App\Modules\Nureen\Notifications\AppointmentLetterIssuedToCgs;
use App\Modules\Nureen\Notifications\AppointmentLetterRevoked;
use App\Modules\Nureen\Notifications\SupervisionFinalDecisionCc;
use App\Modules\Nureen\Support\AppointmentReferenceGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\UnauthorizedException;

class SupervisionController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'supervision';
    }

    public function create(Request $request)
    {
        $supervisors = User::where('role', Role::SUPERVISOR)->orderBy('name')->get();

        return view('nureen::supervision.form', [
            'supervisors' => $supervisors,
            'currentSupervisorId' => $request->user()->supervisor_id,
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $student = $request->user();

        $data = $request->validate([
            'requested_supervisor_id' => [
                'required', 'exists:users,id',
                function (string $attribute, $value, \Closure $fail) use ($request, $student) {
                    if ($request->input('appointment_role') !== SupervisionDetail::ROLE_CO) {
                        return;
                    }

                    if ((int) $value === (int) $student->supervisor_id) {
                        $fail('This person is already your Main Supervisor.');

                        return;
                    }

                    if (SupervisionDetail::hasActiveCoSupervisor($student->id, (int) $value)) {
                        $fail('This person is already an active Co-Supervisor for you.');
                    }
                },
            ],
            'appointment_role' => ['required', 'in:'.SupervisionDetail::ROLE_MAIN.','.SupervisionDetail::ROLE_CO],
            'appointment_type' => ['required', 'in:new,existing'],
            'justification' => ['required', 'string', 'min:20', 'max:2000'],
            // The scope calls for the request to carry its supporting
            // documentation and for completeness to be checked before it
            // reaches the supervisor, so at least one file is required
            // rather than optional -- the same bar GA Extension sets. See
            // DocumentStore::manyRules() for the shared count/size/type
            // limits.
            ...DocumentStore::manyRules('supporting_documents'),
        ], [
            'justification.min' => 'Please give a sentence or two on why you are requesting this supervisor.',
            'supporting_documents.required' => 'Attach your research proposal or supporting document.',
            ...DocumentStore::manyMessages('supporting_documents'),
        ]);

        // The dropdown only ever lists supervisors, but the id is still
        // user-supplied -- re-check role server-side rather than trusting it.
        $supervisor = User::where('id', $data['requested_supervisor_id'])
            ->where('role', Role::SUPERVISOR)
            ->firstOrFail();

        $application = DB::transaction(function () use ($request, $supervisor, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            SupervisionDetail::create([
                'application_id' => $application->id,
                'requested_supervisor_id' => $supervisor->id,
                'justification' => $data['justification'],
                'appointment_role' => $data['appointment_role'],
                'appointment_type' => $data['appointment_type'],
                // Snapshotted now, not re-read later -- see
                // SupervisionWorkflow::stages(). A later change to this
                // config must never alter which chain an already-submitted
                // application is running.
                'cgs_stage_snapshot' => config('nureen.supervision.cgs_stage'),
            ]);

            // Through DocumentStore, so each file lands on the private disk
            // under a random name and is served back only by the authorised
            // download route -- no column on supervision_details, and no
            // new migration.
            $documents->attachMany($application, $request->file('supporting_documents'), 'Supporting Document');

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Supervision request #{$application->id} submitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        // The engine's queue is role-scoped, not person-scoped: every
        // supervisor-role user is handed every pending request at the
        // "supervisor" stage, because the student has no supervisor_id yet
        // for the engine to filter on -- this request is what sets it. Cut
        // the list down to the ones actually addressed to this supervisor so
        // nobody sees, or is tempted to act on, someone else's request.
        //
        // Applied to the QUERY, not to the rows that come back. Filtering
        // afterwards reported the unfiltered total, paged over rows it then
        // threw away, and turned the paginator into a plain Collection --
        // which is a 500 the moment the view asks it for a total. The Chair
        // and CGS stages need no such narrowing: both are role-based, and
        // Chair is already department-scoped generically by
        // ApprovesApplications::queueFor() (see Role::isDepartmentScoped()).
        $queue = $this->queueFor($request, $engine, ['documents'],
            function ($query, Stage $stage) use ($request) {
                if ($stage->key !== 'supervisor') {
                    return;
                }

                $query->whereIn('id', SupervisionDetail::query()
                    ->where('requested_supervisor_id', $request->user()->id)
                    ->select('application_id'));
            });

        $details = SupervisionDetail::with('requestedSupervisor')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('nureen::supervision.queue', $queue + ['details' => $details]);
    }

    /**
     * Overrides the trait's decide(): a supervisor-stage decision must come
     * from the specific supervisor the student named, not merely from
     * someone holding the supervisor role -- see the note in queue() above.
     * Whichever stage turns out to be the LAST one on this application's
     * chain (see SupervisionWorkflow::stages()) is also where the named
     * supervisor is CC'd and, on approval, where the appointment becomes
     * real: see finalizeApproval().
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine, DocumentStore $documents): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $stage = $engine->currentStage($application);
        $detail = SupervisionDetail::where('application_id', $application->id)->first();

        if ($stage?->key === 'supervisor' && $detail?->requested_supervisor_id !== $request->user()->id) {
            abort(403, 'This request was not addressed to you.');
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => [$request->input('decision') === 'reject' ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Say why this request is being rejected — the student is owed a reason.',
        ]);

        try {
            $decided = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        if ($stage && $decided->status !== Application::STATUS_PENDING && $detail) {
            $approved = $data['decision'] === 'approve';

            $supervisor = User::find($detail->requested_supervisor_id);
            $supervisor?->notify(new SupervisionFinalDecisionCc($application, $stage, $approved, $data['remarks'] ?? null));

            if ($approved) {
                $this->finalizeApproval($decided, $detail, $request->user(), $documents);
            }
        }

        $verb = $data['decision'] === 'approve' ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * The appointment becomes real here, on final approval: Main Supervisor
     * writes users.supervisor_id (capturing what it replaces first, so a
     * later revocation can restore it); Co-Supervisor never touches it at
     * all, per the "never overwrite the main supervisor" rule. Either way,
     * the letter is generated and dispatched immediately.
     */
    protected function finalizeApproval(Application $application, SupervisionDetail $detail, User $decider, DocumentStore $documents): void
    {
        if ($detail->appointment_role === SupervisionDetail::ROLE_MAIN) {
            $detail->update(['previous_supervisor_id' => $application->student->supervisor_id]);
            $application->student->update(['supervisor_id' => $detail->requested_supervisor_id]);
        }

        $this->issueLetter($application, $detail, $decider, $documents);
    }

    protected function issueLetter(Application $application, SupervisionDetail $detail, User $endorsedBy, DocumentStore $documents): void
    {
        $detail->update([
            'letter_reference' => app(AppointmentReferenceGenerator::class)->generate(),
            'letter_issued_at' => now(),
        ]);

        $supervisor = User::findOrFail($detail->requested_supervisor_id);

        $pdf = Pdf::loadView('nureen::supervision.appointment_letter_pdf', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'supervisor' => $supervisor,
            'endorsedBy' => $endorsedBy,
        ]);

        $letter = $documents->storeGenerated(
            $application,
            $pdf->output(),
            "appointment-{$application->id}.pdf",
            'Supervisor Appointment Letter',
        );

        $application->student?->notify(new AppointmentLetterIssued($application, $detail, $letter, $supervisor));

        // "Non-Executive CGS is notified of every issued letter" -- being
        // able to find it on the Issued Appointment Letters list is not
        // the same as being told the moment it happens.
        foreach (User::where('role', Role::NON_EXEC_CGS)->get() as $cgsStaff) {
            $cgsStaff->notify(new AppointmentLetterIssuedToCgs($application, $detail));
        }
    }

    /**
     * Every appointment letter ever issued, Valid or Revoked -- same
     * pattern as CertificationController::issued(). An approved
     * application drops out of the pending queue, so this is where Revoke
     * is actually reachable once the letter exists.
     */
    public function issued(Request $request)
    {
        $details = SupervisionDetail::whereNotNull('letter_reference')
            ->with('application.student', 'requestedSupervisor')
            ->orderByDesc('id')
            ->paginate(20);

        return view('nureen::supervision.issued', ['details' => $details]);
    }

    /**
     * CGS's own download for the "Issued Appointment Letters" list.
     *
     * Core's ApplicationDocument::visibleTo() grants access to the student,
     * an admin, whoever holds the application's CURRENT stage role, or
     * anyone already in its approval_history -- which covers the student
     * and the appointed supervisor (they show up in history the moment they
     * endorse) but not CGS in 'automatic' mode, where CGS never decides on
     * the application at all. Rather than widen that Core rule, this module
     * gets its own download, gated on the same role as issued()/
     * revokeLetter(). See the research report in this conversation for why.
     */
    public function downloadLetter(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $detail = SupervisionDetail::where('application_id', $application->id)->firstOrFail();
        abort_if($detail->letter_reference === null, 404, 'No appointment letter has been issued for this application.');

        $letter = $application->documents()
            ->where('doc_type', 'Supervisor Appointment Letter')
            ->latest()
            ->firstOrFail();

        return Storage::disk('local')->download($letter->path, $letter->original_name);
    }

    /**
     * Marks the appointment letter invalid without touching the
     * application's own status -- same shape as
     * CertificationController::revoke(). For a Main Supervisor letter, also
     * restores users.supervisor_id to whoever it was before, UNLESS that
     * column has since moved on to a different appointment -- overwriting a
     * later, unrelated appointment would be worse than leaving this one
     * alone, so that case is left untouched and reported back instead.
     */
    public function revokeLetter(Request $request, Application $application): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $detail = SupervisionDetail::where('application_id', $application->id)->firstOrFail();

        abort_if($detail->letter_reference === null, 404, 'No appointment letter has been issued for this application.');
        abort_if($detail->isRevoked(), 404, 'This letter has already been revoked.');

        $data = $request->validate([
            'revoked_reason' => ['required', 'string', 'max:255'],
        ], [
            'revoked_reason.required' => 'Say why this letter is being revoked — it is shown nowhere public, but is kept for the record.',
        ]);

        $note = null;

        DB::transaction(function () use ($detail, $application, $data, &$note) {
            if ($detail->appointment_role === SupervisionDetail::ROLE_MAIN) {
                $student = User::where('id', $application->student_id)->lockForUpdate()->first();

                if ($student && (int) $student->supervisor_id === (int) $detail->requested_supervisor_id) {
                    $student->update(['supervisor_id' => $detail->previous_supervisor_id]);
                } else {
                    $note = 'users.supervisor_id had already changed to a later appointment and was left untouched.';
                }
            }

            $detail->update([
                'letter_revoked_at' => now(),
                'letter_revoked_reason' => $data['revoked_reason'],
                'letter_revoke_note' => $note,
            ]);
        });

        $application->student?->notify(new AppointmentLetterRevoked($detail));
        User::find($detail->requested_supervisor_id)?->notify(new AppointmentLetterRevoked($detail));

        $status = "Appointment letter {$detail->letter_reference} revoked.";

        if ($note) {
            $status .= ' The main supervisor was NOT changed: '.$note;
        }

        return back()->with('status', $status);
    }
}
