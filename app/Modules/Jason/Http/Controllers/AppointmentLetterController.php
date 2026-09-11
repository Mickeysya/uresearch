<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
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
use Illuminate\Support\Facades\Storage;
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
     * One bar per person holding a stage role in this chain (Academic Exec,
     * Dean), showing how many appointment-letter nominations are currently
     * sitting there. Stages are role-owned, not assigned to one person, so
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
        $student = $application->student;
        $issuedAt = now();

        $pdf = Pdf::loadView('jason::appointment_letter.pdf', [
            'application' => $application,
            'detail' => $detail,
            'student' => $student,
            'issuedAt' => $issuedAt,
        ]);

        $contents = $pdf->output();
        $filename = 'Appointment-Letter-'.Str::slug($detail->examiner_name).'-'.$application->id.'.pdf';

        // Same private-disk, random-path convention as DocumentStore -- this
        // just cannot go through DocumentStore::attach() itself, which only
        // accepts an already-uploaded file, not generated bytes.
        $path = "applications/{$application->id}/".Str::random(40).'.pdf';
        Storage::disk('local')->put($path, $contents);

        $application->documents()->create([
            'doc_type' => 'Appointment Letter',
            'original_name' => $filename,
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
        ]);

        Mail::to($detail->examiner_email)->send(
            new AppointmentLetterMail($application, $detail, $contents, $filename)
        );
    }
}
