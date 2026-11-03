<?php

namespace App\Modules\Hani\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Support\ExaminerDivergence;
use App\Modules\Hani\Support\ExaminerProgress;
use App\Modules\Hani\Support\PanelSeats;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The AE's one screen per student for the examiners: Letter sent ->
 * Accepted / Rejected -> Thesis received -> Report returned.
 *
 * Today the acceptance slip and the report come back on paper or by email
 * and are recorded nowhere; this is where they are entered, so the system
 * can chase what is late and flag reports that disagree. Recording
 * "Rejected" is a decline: PanelSeats frees the examiner and promotes the
 * backup, and the backup gets a row of their own.
 *
 * The candidate's own Academic Executive, or the Senior Executive CGS. Never
 * a student: examiner names, categories and reports are staff-only.
 */
class VivaExaminerController extends Controller
{
    /** Examiner report upload: PDF, 10 MB. */
    protected const REPORT_MAX_KB = 10240;

    public function show(Request $request, Application $application, ExaminerProgress $progress)
    {
        [$viva, $panel] = $this->load($request->user(), $application);

        if (! $panel) {
            return redirect()->route('viva.schedule.index')
                ->with('error', 'The examiners are not appointed yet: the nomination has to be released first.');
        }

        $current = $progress->sync($application, $panel);

        return view('hani::viva.examiners', [
            'progress' => $viva,
            'panel' => $panel,
            'current' => $current,
            'history' => $progress->history($application),
            'divergence' => ExaminerDivergence::between($current),
            'dispatchAvailable' => $progress->dispatchAvailable(),
            'level' => $viva->detail?->programme_level,
            'criteria' => config('hani.examiner_progress.criteria'),
            'sections' => config('hani.examiner_progress.sections'),
        ]);
    }

    public function update(Request $request, Application $application, VivaExaminerProgress $row, ExaminerProgress $progress, PanelSeats $seats): RedirectResponse
    {
        [, $panel] = $this->load($request->user(), $application);
        abort_unless($panel && $row->viva_application_id === $application->id && $row->nomination_id === $panel->id, 404);

        if ($row->superseded_at !== null) {
            return back()->with('error', 'That examiner is no longer on the panel, so their record is closed.');
        }

        $ratingKeys = fn (string $group) => array_keys(config("hani.examiner_progress.{$group}"));

        $data = $request->validate([
            'letter_sent_at' => ['nullable', 'date', 'before_or_equal:today'],
            'acknowledgement' => ['nullable', Rule::in(['accepted', 'rejected'])],
            'acknowledged_at' => ['nullable', 'required_with:acknowledgement', 'date', 'before_or_equal:today'],
            'rejection_reason' => ['nullable', 'required_if:acknowledgement,rejected', 'string', 'max:1000'],
            'thesis_received_at' => ['nullable', 'date', 'before_or_equal:today'],
            'report_received_at' => ['nullable', 'date', 'before_or_equal:today'],
            'category' => ['nullable', 'required_with:report_received_at', 'integer', 'between:1,5'],
            'report' => ['nullable', 'file', 'mimes:pdf', 'max:'.self::REPORT_MAX_KB],
            'ratings' => ['nullable', 'array'],
            'ratings.criteria' => ['nullable', 'array'],
            'ratings.criteria.*' => ['nullable', 'integer', 'between:1,5'],
            'ratings.sections' => ['nullable', 'array'],
            'ratings.sections.*' => ['nullable', 'integer', 'between:1,5'],
        ], [
            'acknowledged_at.required_with' => 'Give the date the examiner accepted or rejected the appointment.',
            'rejection_reason.required_if' => 'Say why the examiner rejected the appointment. It is kept on the panel history.',
            'category.required_with' => 'Record the category the examiner recommends.',
        ]);

        // The steps in order: no thesis before acceptance, no report before the thesis.
        $accepted = ($data['acknowledgement'] ?? $row->acknowledgement) === 'accepted';
        $thesis = $data['thesis_received_at'] ?? $row->thesis_received_at;
        $errors = [];
        if (filled($data['thesis_received_at'] ?? null) && ! $accepted) {
            $errors['thesis_received_at'] = 'Record the examiner\'s acceptance before the thesis is received.';
        }
        if (filled($data['report_received_at'] ?? null) && ! $thesis) {
            $errors['report_received_at'] = 'Record when the examiner received the thesis before the report.';
        }
        if (filled($data['letter_sent_at'] ?? null) && $row->letter_source === 'dispatch') {
            $errors['letter_sent_at'] = 'The letter date comes from the appointment letter module and cannot be changed here.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $ratings = array_filter([
            'criteria' => array_filter(array_intersect_key($data['ratings']['criteria'] ?? [], array_flip($ratingKeys('criteria')))),
            'sections' => array_filter(array_intersect_key($data['ratings']['sections'] ?? [], array_flip($ratingKeys('sections')))),
        ]);

        $rejecting = ($data['acknowledgement'] ?? null) === 'rejected' && $row->acknowledgement !== 'rejected';
        $user = $request->user();

        $result = DB::transaction(function () use ($row, $data, $ratings, $rejecting, $request, $user, $panel, $seats) {
            $row = VivaExaminerProgress::whereKey($row->id)->lockForUpdate()->firstOrFail();

            $fill = array_filter([
                'letter_sent_at' => $data['letter_sent_at'] ?? null,
                'acknowledgement' => $data['acknowledgement'] ?? null,
                'acknowledged_at' => $data['acknowledged_at'] ?? null,
                'rejection_reason' => ($data['acknowledgement'] ?? null) === 'rejected' ? $data['rejection_reason'] : null,
                'thesis_received_at' => $data['thesis_received_at'] ?? null,
                'report_received_at' => $data['report_received_at'] ?? null,
                'category' => $data['category'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            if (isset($fill['letter_sent_at'])) {
                $fill['letter_source'] = 'manual';
            }
            if ($ratings) {
                $fill['ratings'] = $ratings;
            }
            if ($request->hasFile('report')) {
                $file = $request->file('report');
                // Private disk, random name, OUTSIDE DocumentStore: see the migration.
                $fill['report_path'] = $file->store('hani/examiner-reports', 'local');
                $fill['report_original_name'] = $file->getClientOriginalName();

                activity('document')->causedBy($user)->performedOn($row)
                    ->withProperties(['action' => 'uploaded', 'doc_type' => 'Examiner Report', 'original_name' => $file->getClientOriginalName(), 'application_id' => $row->viva_application_id])
                    ->log('Uploaded examiner report');
            }

            $row->fill($fill + ['updated_by_id' => $user->id])->save();

            // A rejection is a decline: free the examiner, promote the backup.
            return $rejecting
                ? $seats->decline($panel, $row->seat, 'Rejected the appointment: '.$data['rejection_reason'], $user)
                : null;
        });

        $progress->sync($application, $panel->fresh());

        $name = $row->examiner?->name ?? 'The examiner';

        return redirect()->route('viva.examiners.show', $application)->with('status', match (true) {
            $result && $result['promoted'] !== null => "{$name} rejected the appointment and has been freed. Backup {$result['promoted']->name} has been promoted to the seat, and Non-Executive CGS has been asked to send the appointment letter.",
            $result !== null => "{$name} rejected the appointment and has been freed. There is no eligible backup: appoint a replacement on the panel page.",
            default => "{$name}'s progress has been saved.",
        });
    }

    /** The examiner's report, streamed from the private disk. Logged like a document view. */
    public function report(Request $request, Application $application, VivaExaminerProgress $row)
    {
        $this->load($request->user(), $application);
        abort_unless($row->viva_application_id === $application->id && $row->report_path, 404);
        abort_unless(Storage::disk('local')->exists($row->report_path), 404);

        activity('document')->causedBy($request->user())->performedOn($row)
            ->withProperties(['action' => 'viewed', 'doc_type' => 'Examiner Report', 'application_id' => $application->id, 'student' => $application->student?->name])
            ->log('Viewed examiner report');

        return Storage::disk('local')->download($row->report_path, $row->report_original_name ?: 'examiner-report.pdf');
    }

    /**
     * The viva and its released panel, for the candidate's own AE or the
     * Senior Executive.
     *
     * @return array{0: VivaProgress, 1: ?ExaminerNomination}
     */
    protected function load(User $user, Application $application): array
    {
        abort_unless($application->module_type === VivaProgress::NOTICE && $application->status === Application::STATUS_APPROVED, 404);

        $allowed = $user->role === Role::SENIOR_EXEC_CGS
            || ($user->role === Role::ACADEMIC_EXEC && filled($user->department) && $user->department === $application->student?->department);
        abort_unless($allowed, 403, 'That student is not in your department.');

        $viva = VivaProgress::forNotices(collect([$application]))->first();

        return [$viva, $viva->panel()];
    }
}
