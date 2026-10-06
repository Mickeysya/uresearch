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
use App\Modules\Jason\Exports\AppointmentTemplateExport;
use App\Modules\Jason\Imports\AppointmentSheetImport;
use App\Modules\Jason\Mail\AppointmentLetterMail;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;
use App\Modules\Jason\Notifications\ExaminersAppointed;
use App\Modules\Jason\Support\AppointmentSheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
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

    /**
     * Where CGS starts: the finalised examiner list arrives as a
     * spreadsheet, and this is the screen that takes it.
     */
    public function importForm(Request $request)
    {
        return view('jason::appointment_letter.import', [
            'columns' => AppointmentSheet::COLUMNS,
        ]);
    }

    /**
     * The blank list, for when CGS has no file from the faculty side.
     */
    public function template(Request $request)
    {
        $stamp = now()->format('Y-m-d');

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () {
                $out = fopen('php://output', 'w');
                fputcsv($out, AppointmentSheet::COLUMNS);
                foreach (AppointmentSheet::sampleRows() as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, "examiner-list-template-{$stamp}.csv", ['Content-Type' => 'text/csv']);
        }

        return Excel::download(new AppointmentTemplateExport(), "examiner-list-template-{$stamp}.xlsx");
    }

    /**
     * Reads the finalised examiner list and opens one appointment per
     * candidate on it, each carrying its own panel.
     *
     * One row per examiner, grouped on matric_no -- see AppointmentSheet.
     * Nothing is written unless every row is good: a list is approved as a
     * whole upstream, and importing half of it would leave CGS to work out
     * which candidates made it.
     *
     * Not routed through DocumentStore: the sheet is not one candidate's
     * document, it is parsed into applications and discarded, the same way
     * the attendance import treats a UTrace export.
     */
    public function import(Request $request, WorkflowEngine $engine)
    {
        $request->validate([
            'sheet' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ], [
            'sheet.required' => 'Choose the examiner list to import.',
        ]);

        try {
            $sheets = Excel::toArray(new AppointmentSheetImport(), $request->file('sheet'));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'That file could not be read. Upload a .csv or .xlsx, '
                .'or start from the template on this page.');
        }

        $rows = $sheets[0] ?? [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $expected = AppointmentSheet::COLUMNS;

        if (array_slice($header, 0, count($expected)) !== $expected) {
            return back()->with('error', 'The first row must be exactly: '.implode(', ', $expected)
                .'. Download the template on this page to start from a correct file.');
        }

        [$candidates, $problems] = $this->readSheet($rows);

        if ($problems !== []) {
            return back()->with('error', 'Nothing was imported. '.implode(' ', array_slice($problems, 0, 6))
                .(count($problems) > 6 ? ' (+'.(count($problems) - 6).' more)' : ''));
        }

        $opened = [];

        DB::transaction(function () use ($candidates, $request, $engine, &$opened) {
            foreach ($candidates as $candidate) {
                $application = Application::create([
                    'student_id' => $candidate['student']->id,
                    // The CGS officer who imported the list. Nobody files
                    // this on a form any more -- the decision that produced
                    // it was taken before the list arrived.
                    'submitted_by_id' => $request->user()->id,
                    'module_type' => $this->moduleKey(),
                    'status' => Application::STATUS_DRAFT,
                ]);

                AppointmentDetail::create([
                    'application_id' => $application->id,
                    'candidate_degree' => $candidate['degree'],
                    'candidate_programme' => $candidate['programme'],
                    'supervisor_name' => $candidate['supervisor_name'],
                    'thesis_title' => $candidate['thesis_title'],
                ]);

                foreach ($candidate['examiners'] as $examiner) {
                    AppointmentExaminer::create(['application_id' => $application->id] + $examiner);
                }

                $opened[] = $engine->submit($application);
            }
        });

        $count = count($opened);

        return redirect()
            ->route('appointment-letter.queue')
            ->with('status', "{$count} ".Str::plural('candidate', $count).' imported. '
                .'Prepare each pack, then send it to the Dean.');
    }

    /**
     * Turns the sheet's rows into one entry per candidate, or into a list of
     * what is wrong with them.
     *
     * Every problem is reported with its row number, because the person
     * repairing the file is looking at row numbers -- being told only how
     * many rows failed is what makes an import unusable.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>}
     */
    protected function readSheet(array $rows): array
    {
        $candidates = [];
        $problems = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            // +2: the header is row 1 and spreadsheets count from 1.
            $line = $i + 2;
            $row = array_map(fn ($cell) => trim((string) $cell), array_pad(array_slice($row, 0, 10), 10, ''));
            [$matric, $degree, $programme, $supervisor, $title, $type, $name, $institution, $email, $address] = $row;

            if (implode('', $row) === '') {
                continue;
            }

            $student = $matric === '' ? null : User::where('role', Role::STUDENT)->where('matric_no', $matric)->first();

            if (! $student) {
                $problems[] = "Row {$line}: no candidate with matric number \"{$matric}\".";

                continue;
            }

            $kind = AppointmentSheet::parseType($type);

            if (! $kind) {
                $problems[] = "Row {$line}: examiner_type must be internal or external, not \"{$type}\".";

                continue;
            }

            foreach (['examiner_name' => $name, 'examiner_email' => $email] as $column => $value) {
                if ($value === '') {
                    $problems[] = "Row {$line}: {$column} is empty.";
                }
            }

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $problems[] = "Row {$line}: \"{$email}\" is not an email address.";
            }

            // The same examiner twice on one panel is a typo, and it would
            // send them the same appointment twice.
            $key = $matric.'|'.strtolower($email);

            if (isset($seen[$key])) {
                $problems[] = "Row {$line}: {$email} is already on this candidate's panel (row {$seen[$key]}).";
            }

            $seen[$key] = $line;

            $candidates[$matric] ??= [
                'student' => $student,
                // The candidate's own columns repeat down their rows; the
                // first row that carries them wins.
                'degree' => $degree,
                'programme' => $programme,
                'supervisor_name' => $supervisor,
                'thesis_title' => $title,
                'examiners' => [],
            ];

            $candidates[$matric]['examiners'][] = [
                'examiner_type' => $kind,
                'examiner_name' => $name,
                'examiner_institution' => $institution,
                'examiner_email' => $email,
                'examiner_address' => $address,
                'letter_ref_no' => 'UTP/'.($kind === AppointmentExaminer::TYPE_INTERNAL ? 'CGS' : 'PGS')."/AD/{$matric}",
            ];
        }

        // A panel is an internal and an external examiner at minimum, the
        // same rule the letters themselves assume.
        foreach ($candidates as $matric => $candidate) {
            $kinds = array_unique(array_column($candidate['examiners'], 'examiner_type'));

            if (count($kinds) < 2) {
                $problems[] = "{$matric}: a panel needs at least one internal and one external examiner.";
            }

            foreach (AppointmentSheet::CANDIDATE_COLUMNS as $column) {
                if (($candidate[$column] ?? '') === '') {
                    $problems[] = "{$matric}: {$column} is empty.";
                }
            }
        }

        return [$candidates, $problems];
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

            $this->announceAppointment($application);
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
     * Tells everyone the appointment concerns: the candidate, their
     * supervisor, the Academic Executive and CGS.
     *
     * The engine notifies the candidate on every decision and nobody else,
     * which is right for a chain a student files. This one is filed by CGS
     * off the back of a decision taken upstream, so the people who chose the
     * panel and who will run the viva have to hear that it is now real. One
     * notification class, worded from the recipient's side.
     */
    protected function announceAppointment(Application $application): void
    {
        $lines = $this->examinerLines(AppointmentExaminer::where('application_id', $application->id)->get());
        $student = $application->student;

        $recipients = collect([$student, $student?->supervisor])
            ->merge(User::whereIn('role', [Role::ACADEMIC_EXEC, Role::NON_EXEC_CGS])
                ->when($student?->department, fn ($q, $department) => $q->where(
                    fn ($inner) => $inner->where('department', $department)->orWhere('role', Role::NON_EXEC_CGS)
                ))
                ->get())
            ->filter()
            ->unique('id');

        foreach ($recipients as $recipient) {
            $recipient->notify(new ExaminersAppointed($application, $lines));
        }
    }

    /**
     * "Name (internal)" per examiner, internal first, for the candidate's notices.
     *
     * @param  \Illuminate\Support\Collection<int, AppointmentExaminer>  $examiners
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
