<?php

namespace App\Modules\Jason\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\ModuleRegistry;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Jason\Mail\AppointmentLetterMail;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Models\PoolExaminer;
use App\Modules\Jason\Notifications\ExaminerPanelNominated;
use App\Modules\Jason\Notifications\ExaminersAppointed;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    public const DOC_LETTER = 'Appointment Letter';

    public const DOC_REPORT = 'Thesis Evaluation Report';

    protected function moduleKey(): string
    {
        return 'appointment_letter';
    }

    public function create(Request $request)
    {
        // `appointments` is eager-loaded so the availability check on each
        // examiner is a read from memory, not a query per person -- the list
        // is a hundred people and grows.
        $pool = PoolExaminer::active()->with('appointments')->orderBy('name')->get();

        return view('jason::appointment_letter.form', [
            // Scoped to the Chair's own department -- the same "an approver
            // only sees rows that are theirs" rule the queues already enforce.
            'candidates' => User::where('role', Role::STUDENT)
                ->where('department', $request->user()->department)
                ->orderBy('name')
                ->get(),
            // Only the pickable ones reach the dropdown. At this size, listing
            // people who cannot be chosen is noise; the count of who is left
            // out goes on the page, and the Examiner List says who and until
            // when, so nobody has to wonder where a name went.
            'pool' => $pool->filter->isAvailable(),
            'unavailable' => $pool->reject->isAvailable(),
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
            'examiners' => ['required', 'array', 'min:2'],
            'examiners.*.pool_id' => [
                'required', 'distinct',
                Rule::exists('appointment_examiner_pool', 'id')->where('is_active', true),
            ],
        ], [
            'student_id.exists' => 'You may only nominate examiners for candidates in your own department.',
            'examiners.min' => 'Nominate at least one internal and one external examiner.',
            'examiners.*.pool_id.required' => 'Pick an examiner for every slot.',
            'examiners.*.pool_id.distinct' => 'The same examiner is on the panel twice.',
            'examiners.*.pool_id.exists' => 'That examiner is no longer on the list.',
        ]);

        $chosen = PoolExaminer::whereIn('id', collect($data['examiners'])->pluck('pool_id'))->get();

        // A panel is an internal and an external examiner at minimum. Two
        // externals and no internal is not a panel.
        if ($chosen->pluck('examiner_type')->unique()->count() < 2) {
            return back()->withInput()->withErrors([
                'examiners' => 'The panel needs at least one internal and one external examiner.',
            ]);
        }

        // The form greys these out, but a disabled <option> is only a
        // courtesy -- the rule is enforced here.
        $busy = $chosen->reject->isAvailable();

        if ($busy->isNotEmpty()) {
            return back()->withInput()->withErrors([
                'examiners' => $busy->map(fn ($e) => $e->name.' is '.$e->unavailableLabel())->implode('; ')
                    .'. An examiner takes one assignment at a time.',
            ]);
        }

        $application = DB::transaction(function () use ($request, $data, $engine, $chosen) {
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

            AppointmentDetail::create(['application_id' => $application->id]);

            // Copied, not referenced: the letters must say what the examiner's
            // details were on the day, whatever later happens to the list entry.
            foreach ($chosen as $examiner) {
                AppointmentExaminer::create(['application_id' => $application->id] + $examiner->toSnapshot());
            }

            // Hands the nomination to the Academic Executive.
            return $engine->submit($application);
        });

        // The engine announces decisions, not submissions -- every other
        // chain is started by the student, who needs no telling. This one
        // is filed on the candidate's behalf, so tell them.
        $application->student?->notify(new ExaminerPanelNominated(
            $application,
            $request->user()->name,
            $this->examinerLines($chosen),
        ));

        return redirect()
            ->route('appointment-letter.create')
            ->with('status', "Nomination #{$application->id} with {$chosen->count()} examiners submitted to the Academic Executive.");
    }

    public function queue(Request $request, WorkflowEngine $engine, ModuleRegistry $registry)
    {
        $queue = $this->queueFor($request, $engine, ['submittedBy', 'documents']);

        $ids = $queue['applications']->pluck('id');

        return view('jason::appointment_letter.queue', $queue + [
            'details' => AppointmentDetail::whereIn('application_id', $ids)->get()->keyBy('application_id'),
            'examiners' => AppointmentExaminer::whereIn('application_id', $ids)->get()->groupBy('application_id'),
            'workload' => $this->workload($engine, $registry),
        ]);
    }

    /**
     * The Non-Executive CGS preparation screen, sitting between the Academic
     * Executive's endorsement and the Dean's approval.
     *
     * Everything the letters need is pre-filled from records the portal
     * already holds -- see prepDefaults() -- so in practice this screen is a
     * confirmation, not a data-entry form.
     */
    public function prepare(Request $request, Application $application, WorkflowEngine $engine)
    {
        $detail = $this->detailAwaitingPreparation($application, $engine);

        return view('jason::appointment_letter.prepare', [
            'application' => $application,
            'detail' => $detail,
            'student' => $application->student,
            'examiners' => $detail->examiners,
            'defaults' => $this->prepDefaults($application, $detail),
        ]);
    }

    /**
     * Saves the prepared panel, generates every document the Dean will
     * review -- an appointment letter and a thesis evaluation report for each
     * examiner -- and hands the nomination on. The stage move is still the
     * engine's to make; this only writes the module's own rows first.
     */
    public function savePreparation(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        $detail = $this->detailAwaitingPreparation($application, $engine);
        $examinerIds = $detail->examiners->pluck('id')->all();

        $data = $request->validate([
            'candidate_degree' => ['required', 'string', 'max:150'],
            'candidate_programme' => ['required', 'string', 'max:150'],
            'supervisor_name' => ['required', 'string', 'max:150'],
            'thesis_title' => ['required', 'string', 'max:500'],
            'examiners' => ['required', 'array'],
            'examiners.*.id' => ['required', Rule::in($examinerIds)],
            'examiners.*.examiner_type' => ['required', Rule::in([AppointmentExaminer::TYPE_INTERNAL, AppointmentExaminer::TYPE_EXTERNAL])],
            'examiners.*.examiner_address' => ['required', 'string', 'max:500'],
            'examiners.*.letter_ref_no' => ['required', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($application, $request, $engine, $detail, $data) {
            $detail->fill([
                'candidate_degree' => $data['candidate_degree'],
                'candidate_programme' => $data['candidate_programme'],
                'supervisor_name' => $data['supervisor_name'],
                'thesis_title' => $data['thesis_title'],
                'letter_prepared_at' => now(),
            ])->save();

            foreach ($data['examiners'] as $row) {
                AppointmentExaminer::whereKey($row['id'])->update([
                    'examiner_type' => $row['examiner_type'],
                    'examiner_address' => $row['examiner_address'],
                    'letter_ref_no' => $row['letter_ref_no'],
                ]);
            }

            $this->generatePack($application, $detail->fresh());

            // Remarks are the decision's, not the letters' -- the engine
            // writes them to approval_history.
            $engine->decide($application, $request->user(), 'approve', $data['remarks'] ?? null);
        });

        $count = count($data['examiners']) * 2;

        return redirect()
            ->route('appointment-letter.queue')
            ->with('status', "Application #{$application->id}: {$count} documents prepared and sent to the Dean for approval.");
    }

    /**
     * The application's detail row, with the guard that this really is an
     * appointment nomination sitting on the preparation stage. The engine
     * re-checks the acting role when decide() is finally called; this stops
     * someone opening the form for an application that is past (or short of)
     * that point.
     */
    protected function detailAwaitingPreparation(Application $application, WorkflowEngine $engine): AppointmentDetail
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $detail = AppointmentDetail::where('application_id', $application->id)->firstOrFail();

        if ($engine->currentStage($application)?->key === 'cgs_prep') {
            return $detail;
        }

        // The usual way here is the browser's Back button right after a
        // successful submit, so say what actually happened rather than 403.
        $message = match (true) {
            $detail->isPrepared() && $application->status === Application::STATUS_PENDING
                => "Application #{$application->id} has already been prepared and is with the Dean.",
            $detail->isPrepared()
                => "Application #{$application->id} has already been prepared and decided ({$application->status}).",
            $application->status === Application::STATUS_PENDING
                => "Application #{$application->id} has not reached CGS yet. It is still with the Academic Executive.",
            default
                => "Application #{$application->id} is {$application->status}; there is nothing to prepare.",
        };

        throw new HttpResponseException(
            redirect()->route('appointment-letter.queue')->with('status', $message)
        );
    }

    /**
     * The automation. Every field on the letters except the thesis title
     * comes from a record the portal already holds: the candidate and their
     * matric number, department and supervisor are all on `users`, and the
     * date is simply now(). Only the thesis title has no source -- no built
     * module captures one yet -- so it is the single field CGS actually types.
     *
     * Values already saved win, so reopening the form shows what was entered
     * rather than recomputing over the top of it.
     *
     * @return array{candidate: array<string, string|null>, examiners: array<int, array<string, string|null>>}
     */
    protected function prepDefaults(Application $application, AppointmentDetail $detail): array
    {
        $student = $application->student;
        $programme = $student?->programme ?? '';

        $level = match (true) {
            str_contains(strtolower($programme), 'phd') => 'PhD',
            str_contains(strtolower($programme), 'msc'), str_contains(strtolower($programme), 'master') => 'MSc',
            default => '',
        };

        $field = $student?->department;
        $matric = $student?->matric_no ?? $application->id;

        $examiners = [];

        foreach ($detail->examiners as $examiner) {
            $examiners[$examiner->id] = [
                'examiner_type' => $examiner->examiner_type,
                'examiner_address' => $examiner->examiner_address ?? $examiner->examiner_institution,
                'letter_ref_no' => $examiner->letter_ref_no ?? "UTP/{$examiner->refSeries()}/AD/{$matric}",
            ];
        }

        return [
            'candidate' => [
                'candidate_degree' => $detail->candidate_degree ?? trim($level.($level && $field ? ' in '.$field : $field ?? '')),
                'candidate_programme' => $detail->candidate_programme ?? $field,
                'supervisor_name' => $detail->supervisor_name ?? $student?->supervisor?->name,
                'thesis_title' => $detail->thesis_title,
            ],
            'examiners' => $examiners,
        ];
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
     * Overrides the trait's generic decide() only to hook in dispatch: on the
     * Dean's approval specifically, each examiner has to be emailed their
     * pack, and examiners have no account, so that cannot go through a
     * Notification at all -- it has to happen here, once, right after the
     * engine records the approval. Everything else (validation,
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
        // form, which is what actually writes the letters. Waving it through
        // from the queue's generic Approve button would hand the Dean nothing
        // to read. Rejecting from the queue stays available, as at every
        // other stage.
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
            // The appointment is real from this moment: it starts each
            // examiner's cooldown, whether or not the mail gets through.
            AppointmentExaminer::where('application_id', $application->id)
                ->whereNull('appointed_at')
                ->update(['appointed_at' => now()]);

            $this->dispatchPacks($application);

            $application->student?->notify(new ExaminersAppointed(
                $application,
                $this->examinerLines(AppointmentExaminer::where('application_id', $application->id)->get()),
            ));
        }

        $verb = $approved ? 'approved' : 'rejected';

        return back()->with('status', "Application #{$application->id} {$verb}.");
    }

    /**
     * Every nomination the Dean has approved, with the delivery state of each
     * examiner's pack.
     *
     * The gap this closes: dispatch happens after the engine has committed
     * the approval, so a bad address or an unwritable document directory used
     * to lose a pack silently -- the nomination read "approved" and nobody
     * learned the examiner had received nothing until they failed to return a
     * report. Anything listed here as not delivered can be sent again.
     */
    public function issued(Request $request)
    {
        $applications = Application::where('module_type', $this->moduleKey())
            ->where('status', Application::STATUS_APPROVED)
            ->with('student')
            ->orderByDesc('id')
            ->get();

        return view('jason::appointment_letter.issued', [
            'applications' => $applications,
            'examiners' => AppointmentExaminer::whereIn('application_id', $applications->pluck('id'))
                ->orderByRaw("CASE WHEN examiner_type = 'internal' THEN 0 ELSE 1 END")
                ->get()
                ->groupBy('application_id'),
        ]);
    }

    /**
     * Sends an examiner's pack again. The same archived bytes the Dean
     * approved, so a resend can never differ from the original -- and it does
     * not touch the appointment itself, only the delivery.
     */
    public function resend(Request $request, Application $application, AppointmentExaminer $examiner): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($examiner->application_id === $application->id, 404);
        abort_unless($application->status === Application::STATUS_APPROVED, 403);

        $sent = $this->dispatchPacks($application, collect([$examiner]));

        if ($sent === 0) {
            return back()->with('error',
                "Nothing was sent to {$examiner->examiner_name} — the prepared documents for that examiner are missing from the archive. "
                .'Ask CGS to prepare the pack again before resending.');
        }

        return back()->with('status',
            "Appointment pack queued again for {$examiner->examiner_name} ({$examiner->examiner_email}).");
    }

    /**
     * "Name (internal)" per examiner, internal first, for the candidate's notices.
     *
     * @param  \Illuminate\Support\Collection<int, PoolExaminer|AppointmentExaminer>  $examiners
     * @return array<int, string>
     */
    protected function examinerLines($examiners): array
    {
        return $examiners
            ->sortBy(fn ($e) => $e->isInternal() ? 0 : 1)
            ->map(fn ($e) => ($e->name ?? $e->examiner_name).' ('.$e->examiner_type.')')
            ->values()
            ->all();
    }

    /**
     * Generates and archives the full pack at preparation time -- an
     * appointment letter and a thesis evaluation report for every examiner
     * on the panel -- so the Dean approves documents that already exist
     * rather than ones that will be produced afterwards. They land as
     * ApplicationDocuments, which is what the Dean's queue lists.
     */
    protected function generatePack(Application $application, AppointmentDetail $detail): void
    {
        $store = app(DocumentStore::class);
        $dean = User::where('role', Role::DEAN_PGR)->orderBy('name')->first();

        foreach ($detail->examiners as $examiner) {
            $slug = Str::slug($examiner->examiner_name);
            $type = ucfirst($examiner->examiner_type);

            $letter = Pdf::loadView('jason::appointment_letter.letter', [
                'application' => $application,
                'detail' => $detail,
                'examiner' => $examiner,
                'student' => $application->student,
                'issuedAt' => $detail->letter_prepared_at,
                'dean' => $dean,
            ])->output();

            $store->storeGenerated($application, $letter,
                "Appointment-Letter-{$type}-{$slug}.pdf", self::DOC_LETTER);

            $report = Pdf::loadView('jason::appointment_letter.report', [
                'application' => $application,
                'detail' => $detail,
                'examiner' => $examiner,
                'student' => $application->student,
            ])->output();

            $store->storeGenerated($application, $report,
                "Examiner-Report-{$type}-{$slug}.pdf", self::DOC_REPORT);
        }
    }

    /**
     * Emails each examiner the two documents that carry their name. Reads the
     * archived copies the Dean approved rather than regenerating, so what
     * goes out is exactly what was reviewed -- which is also why a resend is
     * safe: it sends the same approved bytes.
     *
     * One examiner failing must not stop the rest, and a failure must not
     * take down a decision the engine has already committed. `pack_sent_at`
     * is not set here: the MessageSent listener sets it when the transport
     * actually accepts the message.
     *
     * @param  \Illuminate\Support\Collection<int, AppointmentExaminer>|null  $only
     */
    protected function dispatchPacks(Application $application, $only = null): int
    {
        $detail = AppointmentDetail::where('application_id', $application->id)->firstOrFail();

        $documents = ApplicationDocument::where('application_id', $application->id)
            ->whereIn('doc_type', [self::DOC_LETTER, self::DOC_REPORT])
            ->get();

        $sent = 0;

        foreach ($only ?? $detail->examiners as $examiner) {
            $suffix = '-'.ucfirst($examiner->examiner_type).'-'.Str::slug($examiner->examiner_name).'.pdf';

            $files = $documents
                ->filter(fn ($doc) => str_ends_with($doc->original_name, $suffix))
                ->map(fn ($doc) => [
                    'name' => $doc->original_name,
                    'contents' => Storage::disk('local')->get($doc->path),
                ])
                ->values()
                ->all();

            if ($files === []) {
                continue;
            }

            try {
                Mail::to($examiner->examiner_email)->send(
                    new AppointmentLetterMail($application, $examiner, $files)
                );
                $sent++;
            } catch (\Throwable $e) {
                // The Dean's approval is already committed and the documents
                // are archived; one unreachable examiner is a resend, not a
                // failed approval.
                report($e);
            }
        }

        return $sent;
    }
}
