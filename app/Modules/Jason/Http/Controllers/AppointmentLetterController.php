<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Mail\AppointmentLetterMail;
use App\Modules\Jason\Models\AppointmentDetail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\UnauthorizedException;

class AppointmentLetterController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'appointment_letter';
    }

    public function create(Request $request)
    {
        return view('jason::appointment_letter.form', [
            // Scoped to the Chair's own department -- the same "an approver
            // only sees rows that are theirs" rule the queues already enforce.
            'candidates' => User::where('role', Role::STUDENT)
                ->where('department', $request->user()->department)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'student_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('role', Role::STUDENT)
                    ->where('department', $request->user()->department),
            ],
            'examiner_name' => ['required', 'string', 'max:150'],
            'examiner_institution' => ['required', 'string', 'max:150'],
            'examiner_email' => ['required', 'email', 'max:150'],
            'examiner_expertise' => ['required', 'string', 'max:255'],
        ], [
            'student_id.exists' => 'You may only nominate examiners for candidates in your own department.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine) {
            $application = Application::create([
                // The candidate this appointment concerns, so it shows on
                // their own tracking page and they receive the standard
                // progress notifications the engine already sends.
                'student_id' => $data['student_id'],
                // The Chair who actually filed the nomination.
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            AppointmentDetail::create([
                'application_id' => $application->id,
                'examiner_name' => $data['examiner_name'],
                'examiner_institution' => $data['examiner_institution'],
                'examiner_email' => $data['examiner_email'],
                'examiner_expertise' => $data['examiner_expertise'],
            ]);

            // Hands the nomination to the Academic Executive.
            return $engine->submit($application);
        });

        return redirect()
            ->route('appointment-letter.create')
            ->with('status', "Nomination #{$application->id} submitted to the Academic Executive.");
    }

    public function queue(Request $request, WorkflowEngine $engine, ModuleRegistry $registry)
    {
        $queue = $this->queueFor($request, $engine, ['submittedBy']);

        $details = AppointmentDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('jason::appointment_letter.queue', $queue + [
            'details' => $details,
            'workload' => $this->workload($engine, $registry),
        ]);
    }

    /**
     * The Non-Executive CGS letter-preparation screen, sitting between the
     * Academic Executive's endorsement and the Dean's approval.
     *
     * Everything the letter needs is pre-filled from records the portal
     * already holds -- see letterDefaults() -- so in practice this screen is
     * a confirmation, not a data-entry form.
     */
    public function prepare(Request $request, Application $application, WorkflowEngine $engine)
    {
        $detail = $this->detailAwaitingPreparation($application, $engine);

        return view('jason::appointment_letter.prepare', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'defaults' => $this->letterDefaults($application, $detail),
        ]);
    }

    /**
     * Saves the prepared letter and hands it to the Dean. The stage move is
     * still the engine's to make -- this only writes the module's own detail
     * row before calling decide().
     */
    public function savePreparation(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        $detail = $this->detailAwaitingPreparation($application, $engine);

        $data = $request->validate([
            'examiner_type' => ['required', Rule::in([AppointmentDetail::TYPE_INTERNAL, AppointmentDetail::TYPE_EXTERNAL])],
            'examiner_address' => ['required', 'string', 'max:500'],
            'letter_ref_no' => ['required', 'string', 'max:60'],
            'candidate_degree' => ['required', 'string', 'max:150'],
            'candidate_programme' => ['required', 'string', 'max:150'],
            'supervisor_name' => ['required', 'string', 'max:150'],
            'thesis_title' => ['required', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        // Remarks are the decision's, not the letter's -- the engine writes
        // them to approval_history.
        $remarks = $data['remarks'] ?? null;
        unset($data['remarks']);

        DB::transaction(function () use ($application, $request, $engine, $detail, $data, $remarks) {
            $detail->fill($data + ['letter_prepared_at' => now()])->save();

            $engine->decide($application, $request->user(), 'approve', $remarks);
        });

        return redirect()
            ->route('appointment-letter.queue')
            ->with('status', "Letter for application #{$application->id} prepared and sent to the Dean for approval.");
    }

    /**
     * The prepared letter as the examiner will receive it, rendered inline so
     * the Dean can read it before approving rather than approving a document
     * nobody has seen. Same PDF view the dispatch uses.
     */
    public function previewLetter(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $detail = AppointmentDetail::where('application_id', $application->id)->firstOrFail();

        abort_unless($detail->isPrepared(), 404, 'This letter has not been prepared yet.');

        return $this->letterPdf($application, $detail)->stream("appointment-letter-{$application->id}.pdf");
    }

    /**
     * The application's detail row, with the guard that this really is an
     * appointment letter sitting on the preparation stage. The engine re-checks
     * the acting role when decide() is finally called; this stops someone
     * opening the form for an application that is past (or short of) that point.
     */
    protected function detailAwaitingPreparation(Application $application, WorkflowEngine $engine): AppointmentDetail
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($engine->currentStage($application)?->key === 'cgs_prep', 403,
            'That application is not awaiting letter preparation.');

        return AppointmentDetail::where('application_id', $application->id)->firstOrFail();
    }

    /**
     * The automation. Every field on the letter except the thesis title comes
     * from a record the portal already holds: the candidate and their matric
     * number, department and supervisor are all on `users`, and the date is
     * simply now(). Only the thesis title has no source -- no built module
     * captures one yet -- so it is the single field CGS actually types.
     *
     * Values already saved win, so reopening the form shows what was entered
     * rather than recomputing over the top of it.
     *
     * @return array<string, string|null>
     */
    protected function letterDefaults(Application $application, AppointmentDetail $detail): array
    {
        $student = $application->student;
        $programme = $student?->programme ?? '';

        $level = match (true) {
            str_contains(strtolower($programme), 'phd') => 'PhD',
            str_contains(strtolower($programme), 'msc'), str_contains(strtolower($programme), 'master') => 'MSc',
            default => '',
        };

        $field = $student?->department;

        $type = $detail->examiner_type ?? $this->guessExaminerType($detail->examiner_institution);

        // The CGS templates file the two letters under different series --
        // PGS for external appointments, CGS for internal ones.
        $series = $type === AppointmentDetail::TYPE_INTERNAL ? 'CGS' : 'PGS';

        return [
            'examiner_type' => $type,
            'examiner_address' => $detail->examiner_address ?? $detail->examiner_institution,
            'letter_ref_no' => $detail->letter_ref_no ?? "UTP/{$series}/AD/".($student?->matric_no ?? $application->id),
            'candidate_degree' => $detail->candidate_degree ?? trim($level.($level && $field ? ' in '.$field : $field ?? '')),
            'candidate_programme' => $detail->candidate_programme ?? $field,
            'supervisor_name' => $detail->supervisor_name ?? $student?->supervisor?->name,
            'thesis_title' => $detail->thesis_title,
        ];
    }

    /**
     * A UTP-hosted examiner is internal, anyone else external -- which decides
     * whether the letter carries the honorarium and travel entitlements.
     */
    protected function guessExaminerType(string $institution): string
    {
        $haystack = strtolower($institution);

        foreach (['universiti teknologi petronas', 'utp', 'petronas'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return AppointmentDetail::TYPE_INTERNAL;
            }
        }

        return AppointmentDetail::TYPE_EXTERNAL;
    }

    /**
     * One bar per person holding a stage role in this chain (Academic Exec,
     * Non-Exec CGS, Dean), showing how many appointment-letter nominations are
     * currently sitting there. Stages are role-owned, not assigned to one person, so
     * everyone sharing a role sees the same count -- this just surfaces that
     * shared backlog by name instead of only as a role-level total.
     *
     * @return array<int, array{label: string, count: int}>
     */
    protected function workload(WorkflowEngine $engine, ModuleRegistry $registry): array
    {
        $bars = [];

        foreach ($registry->get($this->moduleKey())->stages() as $stage) {
            $count = $engine->queue($this->moduleKey(), $stage->key)->count();

            foreach (User::where('role', $stage->role)->orderBy('name')->get() as $person) {
                $bars[] = ['label' => $person->name, 'count' => $count];
            }
        }

        return $bars;
    }

    /**
     * Overrides the trait's generic decide() only to hook in letter issuance:
     * on the Dean's approval specifically, the letter has to be generated and
     * emailed to the examiner, who has no account, so that dispatch cannot go
     * through a Notification at all -- it has to happen here, once, right
     * after the engine records the approval. Everything else (validation,
     * authorisation, the standard ApplicationDecided notification to the
     * candidate) is identical to the trait's default.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        // Captured before decide() moves the application on, so we know
        // which stage this decision was actually made at.
        $stage = $engine->currentStage($application);

        // Approving out of the preparation stage has to go through the prepare
        // form, which is what actually writes the letter. Waving it through
        // from the queue's generic Approve button would hand the Dean -- and
        // then the examiner -- a letter with no candidate details in it.
        // Rejecting from the queue stays available, as at every other stage.
        if ($data['decision'] === 'approve' && $stage?->key === 'cgs_prep') {
            return redirect()->route('appointment-letter.prepare', $application);
        }

        try {
            $application = $engine->decide($application, $request->user(), $data['decision'], $data['remarks'] ?? null);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application has already been decided.');
        }

        $approved = $data['decision'] === 'approve';

        if ($approved && $stage?->key === 'dean') {
            $this->issueAppointmentLetter($application);
        }

        $verb = $approved ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * Generates the Appointment Letter PDF, archives it as an
     * ApplicationDocument (so the existing download route and its permission
     * check apply, same as any uploaded file), and emails it straight to the
     * examiner. Runs once, immediately after the Dean's approval.
     */
    protected function issueAppointmentLetter(Application $application): void
    {
        $detail = AppointmentDetail::where('application_id', $application->id)->firstOrFail();

        $contents = $this->letterPdf($application, $detail)->output();
        $filename = 'Appointment-Letter-'.Str::slug($detail->examiner_name).'-'.$application->id.'.pdf';

        app(DocumentStore::class)->storeGenerated($application, $contents, $filename, 'Appointment Letter');

        Mail::to($detail->examiner_email)->send(
            new AppointmentLetterMail($application, $detail, $contents, $filename)
        );
    }

    /**
     * The letter itself. Dated from letter_prepared_at rather than now(), so
     * the Dean's preview, the archived copy and the examiner's attachment all
     * carry the date CGS prepared it on.
     */
    protected function letterPdf(Application $application, AppointmentDetail $detail): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('jason::appointment_letter.pdf', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'issuedAt' => $detail->letter_prepared_at ?? now(),
            // The letter goes out over the Dean's name, so it is read from the
            // account that holds the role rather than written into the template.
            'dean' => User::where('role', Role::DEAN_PGR)->orderBy('name')->first(),
        ]);
    }
}
