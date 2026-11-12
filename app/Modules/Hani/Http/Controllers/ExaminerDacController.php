<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Hani\Exports\DacCompilationExport;
use App\Modules\Hani\Models\ExaminerDacRecord;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Notifications\NominationNotEndorsed;
use App\Modules\Hani\Support\ConflictOfInterest;
use App\Modules\Hani\Support\CrossDepartmentClashes;
use App\Modules\Hani\Support\VivaProgress;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\UnauthorizedException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * DAC Compilation (UAT 11 Nov 2026). The flow is: student -> supervisor
 * nominates -> the Academic Executive compiles the department's list -> DAC
 * meeting -> only DAC-endorsed nominations go on to CGS.
 *
 * The AE sees her own department's nominations waiting at stage 1
 * (academic_exec), downloads the compiled list for the meeting, then
 * records the outcome for the ones she selects:
 *   endorsed      DAC date (required), reference and minutes (optional);
 *                 each nomination approved through WorkflowEngine::decide()
 *                 one at a time in one transaction, the DAC date and
 *                 reference in the remarks, so approval_history keeps a row
 *                 per nomination. It moves to the Senior Executive.
 *   not endorsed  reason required; the engine's reject. The student and
 *                 the supervisor are told; the supervisor may nominate again.
 * The plain Approve on the stage-1 card is refused
 * (ExaminerNominationController::decide), so nothing reaches CGS without a
 * DAC record.
 */
class ExaminerDacController extends Controller
{
    public const STAGE = 'academic_exec';

    /** @return Collection<int, ExaminerNomination> */
    protected function waiting(User $user, array $ids = []): Collection
    {
        return ExaminerNomination::with(array_merge(ExaminerNomination::slotRelations(), ['application.student', 'application.submittedBy']))
            ->whereHas('application', fn ($q) => $q->where('module_type', VivaProgress::NOMINATION)
                ->where('status', Application::STATUS_PENDING)
                ->where('current_stage', self::STAGE)
                ->whereHas('student', fn ($s) => $s->where('department', $user->department)))
            ->when($ids !== [], fn ($q) => $q->whereIn('application_id', $ids))
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, array<int, array<int, array{level: string, rule: string, message: string}>>> application id => examiner id => flags */
    public static function flags(Collection $nominations): array
    {
        $coi = app(ConflictOfInterest::class);
        $clashes = app(CrossDepartmentClashes::class);
        $flags = [];

        foreach ($nominations as $nomination) {
            $student = $nomination->application?->student;
            if (! $student) {
                continue;
            }
            foreach ($nomination->examiners() as $examiner) {
                $flags[$nomination->application_id][$examiner->id] = array_merge(
                    $coi->flagsFor($examiner, $student),
                    $clashes->flags($examiner->id, $student->department, $nomination->application_id),
                );
            }
        }

        return $flags;
    }

    public function index(Request $request)
    {
        $nominations = $this->waiting($request->user());

        return view('hani::examiner_nomination.dac', [
            'nominations' => $nominations,
            'coiFlags' => self::flags($nominations),
            'department' => $request->user()->department,
            'recent' => ExaminerDacRecord::with('application.student')
                ->whereHas('application.student', fn ($s) => $s->where('department', $request->user()->department))
                ->latest('id')->limit(10)->get(),
        ]);
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'format' => ['required', 'in:xlsx,pdf'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);
        $nominations = $this->waiting($request->user(), $data['ids'] ?? []);
        $flags = self::flags($nominations);
        $name = 'dac-compiled-list-'.Str::slug((string) $request->user()->department).'-'.now()->format('Y-m-d');

        if ($data['format'] === 'xlsx') {
            return Excel::download(new DacCompilationExport($nominations, $flags), $name.'.xlsx');
        }

        return Pdf::loadView('hani::examiner_nomination.dac_pdf', [
            'nominations' => $nominations, 'flags' => $flags, 'department' => $request->user()->department, 'generatedBy' => $request->user(),
        ])->setPaper('a4', 'landscape')->download($name.'.pdf');
    }

    public function endorse(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'meeting_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:150'],
            'minutes' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            'ids.required' => 'Select the nominations the DAC endorsed.',
            'meeting_date.required' => 'Give the DAC meeting date.',
        ]);

        $nominations = $this->waiting($request->user(), $data['ids']);
        if ($nominations->count() !== count(array_unique($data['ids']))) {
            return back()->withInput()->with('error', 'Some of the selected nominations are no longer waiting for the DAC. Nothing was recorded; the list below is current.');
        }

        $minutes = $request->file('minutes');
        $path = $minutes?->store('hani/dac-minutes', 'local');
        $date = \Illuminate\Support\Carbon::parse($data['meeting_date']);
        $remarks = 'Endorsed by the DAC on '.$date->format('j M Y').(! empty($data['reference']) ? ', ref '.$data['reference'] : '').'.';

        try {
            DB::transaction(function () use ($nominations, $engine, $request, $data, $path, $minutes, $remarks) {
                foreach ($nominations as $nomination) {
                    ExaminerDacRecord::create([
                        'nomination_id' => $nomination->id, 'application_id' => $nomination->application_id,
                        'outcome' => ExaminerDacRecord::ENDORSED, 'meeting_date' => $data['meeting_date'],
                        'reference' => $data['reference'] ?? null, 'minutes_path' => $path,
                        'minutes_name' => $minutes?->getClientOriginalName(), 'recorded_by_id' => $request->user()->id,
                    ]);
                    $engine->decide($nomination->application, $request->user(), 'approve', $remarks);
                }
            });
        } catch (UnauthorizedException|\LogicException $e) {
            if ($path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            }

            return back()->withInput()->with('error', 'Nothing was recorded: '.$e->getMessage());
        }

        $listing = app(ExaminerNominationController::class);
        foreach ($nominations as $nomination) {
            $listing->notifyCompileFindings($nomination->application);
        }

        return redirect()->route('examiner-nomination.dac')->with('status', $nominations->count().' nomination(s) recorded as DAC-endorsed on '.$date->format('j M Y').' and sent to the Senior Executive CGS.');
    }

    public function notEndorsed(Request $request, WorkflowEngine $engine)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:2000'],
            'meeting_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'ids.required' => 'Select the nominations the DAC did not endorse.',
            'reason.required' => 'Give the reason: the supervisor and the student are told it.',
        ]);

        $nominations = $this->waiting($request->user(), $data['ids']);
        if ($nominations->count() !== count(array_unique($data['ids']))) {
            return back()->withInput()->with('error', 'Some of the selected nominations are no longer waiting for the DAC. Nothing was recorded; the list below is current.');
        }

        try {
            DB::transaction(function () use ($nominations, $engine, $request, $data) {
                foreach ($nominations as $nomination) {
                    ExaminerDacRecord::create([
                        'nomination_id' => $nomination->id, 'application_id' => $nomination->application_id,
                        'outcome' => ExaminerDacRecord::NOT_ENDORSED, 'meeting_date' => $data['meeting_date'] ?? null,
                        'reason' => $data['reason'], 'recorded_by_id' => $request->user()->id,
                    ]);
                    $engine->decide($nomination->application, $request->user(), 'reject', 'Not endorsed by the DAC: '.$data['reason']);
                }
            });
        } catch (UnauthorizedException|\LogicException $e) {
            return back()->withInput()->with('error', 'Nothing was recorded: '.$e->getMessage());
        }

        foreach ($nominations as $nomination) {
            if ($supervisor = $nomination->application->submittedBy ?? $nomination->application->student?->supervisor) {
                try {
                    $supervisor->notify(new NominationNotEndorsed($nomination->application, $data['reason']));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return redirect()->route('examiner-nomination.dac')->with('status', $nominations->count().' nomination(s) recorded as not endorsed by the DAC. The supervisors and students have been told, and the supervisors may nominate again.');
    }

    /** The minutes, to staff of the department or CGS (the route's roles). */
    public function minutes(Request $request, ExaminerDacRecord $record)
    {
        $user = $request->user();
        abort_unless($record->minutes_path, 404);
        abort_if($user->role === \App\Modules\Core\Support\Role::ACADEMIC_EXEC && $record->application?->student?->department !== $user->department, 403);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($record->minutes_path, $record->minutes_name ?: 'dac-minutes.pdf', ['Content-Type' => 'application/pdf'], 'inline');
    }
}
