<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Models\TravelTaPlan;
use App\Modules\Norhanis\Notifications\TravelSupportingDocumentUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class TravelController extends Controller
{
    use ApprovesApplications;

    /** Fixed per semester. Never trusted from the form -- see store(). */
    protected const TA_HOURS_REQUIRED = 84;

    protected function moduleKey(): string
    {
        return 'travel';
    }

    public function create()
    {
        return view('norhanis::travel.form', [
            'requestTypes' => TravelDetail::requestTypes(),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $request->validate([
            'type_of_request' => ['required', 'in:'.implode(',', array_keys(TravelDetail::requestTypes()))],
            'other_request_specify' => ['nullable', 'string', 'max:255', 'required_if:type_of_request,others'],
            'travel_start_date' => ['required', 'date', 'after_or_equal:today'],
            'travel_end_date' => ['required', 'date', 'after_or_equal:travel_start_date'],
            'reason_for_travel' => ['required', 'string', 'max:255'],
            'destination_address' => ['required', 'string', 'max:255'],
            'is_international' => ['nullable', 'boolean'],
            'contact_person_name' => ['nullable', 'string', 'max:150'],
            'contact_person_no' => ['nullable', 'string', 'max:30'],
            'contact_person_email' => ['required', 'email', 'max:150'],
            'supporting_document' => DocumentStore::rules(),
            'is_ga_gra' => ['required', 'in:yes,no'],
            'ta_hours_completed' => ['required_if:is_ga_gra,yes', 'numeric', 'min:0', 'max:84'],
            'ta_hours_during_travel' => ['required_if:is_ga_gra,yes', 'numeric', 'min:0'],
            'ta_management_method' => ['required_if:is_ga_gra,yes', 'in:'.implode(',', array_keys(TravelTaPlan::managementMethods()))],
            'ta_plan_dates' => ['required_if:ta_management_method,'.implode(',', TravelTaPlan::methodsNeedingDates()), 'array'],
            'ta_plan_dates.*' => ['date'],
        ], [
            'travel_start_date.after_or_equal' => 'Travel cannot start in the past.',
            'travel_end_date.after_or_equal' => 'The end date must fall on or after the start date.',
            'other_request_specify.required_if' => 'Please describe the request when choosing "Others".',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            // Duration is derived, never trusted from the form — the legacy
            // form let the student type any number they liked.
            $start = \Carbon\Carbon::parse($data['travel_start_date']);
            $end = \Carbon\Carbon::parse($data['travel_end_date']);

            $isGaGra = $data['is_ga_gra'] === 'yes';

            TravelDetail::create([
                'application_id' => $application->id,
                'type_of_request' => $data['type_of_request'],
                'other_request_specify' => $data['other_request_specify'] ?? null,
                'travel_start_date' => $start,
                'travel_end_date' => $end,
                'duration_days' => $start->diffInDays($end) + 1,
                'reason_for_travel' => $data['reason_for_travel'],
                'destination_address' => $data['destination_address'],
                'is_international' => $request->boolean('is_international'),
                'contact_person_name' => $data['contact_person_name'] ?? null,
                'contact_person_no' => $data['contact_person_no'] ?? null,
                'contact_person_email' => $data['contact_person_email'],
                'is_ga_gra' => $isGaGra,
            ]);

            if ($isGaGra) {
                $taPlan = TravelTaPlan::create([
                    'application_id' => $application->id,
                    'total_hours_required' => self::TA_HOURS_REQUIRED,
                    'hours_completed' => $data['ta_hours_completed'],
                    'hours_during_travel' => $data['ta_hours_during_travel'],
                    'management_method' => $data['ta_management_method'],
                ]);

                foreach (array_filter($data['ta_plan_dates'] ?? []) as $date) {
                    $taPlan->dates()->create(['date' => $date]);
                }
            }

            if ($request->hasFile('supporting_document')) {
                $documents->attach($application, $request->file('supporting_document'), 'Supporting Document');
            }

            // Hands the application to the first approver in the chain.
            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Travel application #{$application->id} submitted. Your supervisor has been notified.");
    }

    /**
     * The applicant's own page, or anyone holding a stage this application has
     * reached. Built in this module rather than on the shared tracking page
     * because that page is student-only and has no way for a module to add
     * its own detail -- see docs/conventions.md on Core ownership.
     */
    public function show(Request $request, Application $application)
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($this->visibleTo($application, $request->user()), 403);

        $application->load('history.approver', 'documents');
        $detail = TravelDetail::where('application_id', $application->id)->firstOrFail();
        $taPlan = $detail->is_ga_gra
            ? TravelTaPlan::with('dates')->where('application_id', $application->id)->first()
            : null;

        $canUpdateDocument = $request->user()->id === $application->student_id && $detail->is_international;

        return view('norhanis::travel.show', compact('application', 'detail', 'taPlan', 'canUpdateDocument'));
    }

    public function editDocument(Request $request, Application $application)
    {
        [$detail] = $this->authorizeDocumentUpdate($request, $application);

        $currentDocument = $application->documents()->where('doc_type', 'Supporting Document')->latest()->first();

        return view('norhanis::travel.document-edit', compact('application', 'detail', 'currentDocument'));
    }

    public function updateDocument(Request $request, Application $application, DocumentStore $documents)
    {
        $this->authorizeDocumentUpdate($request, $application);

        $request->validate([
            'supporting_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        DB::transaction(function () use ($request, $application, $documents) {
            $old = $application->documents()->where('doc_type', 'Supporting Document')->latest()->first();

            if ($old) {
                Storage::disk('local')->delete($old->path);
                $old->delete();
            }

            $documents->attach($application, $request->file('supporting_document'), 'Supporting Document');

            ApprovalHistory::create([
                'application_id' => $application->id,
                'approver_id' => $request->user()->id,
                'stage_key' => 'supporting_document',
                'stage_label' => 'Supporting Document',
                'decision' => 'updated',
                'remarks' => 'Supporting document updated',
            ]);
        });

        $stage = $application->currentStage();

        if ($stage) {
            Notification::send(User::where('role', $stage->role)->get(), new TravelSupportingDocumentUpdated($application));
        }

        return redirect()
            ->route('travel.show', $application)
            ->with('status', 'Supporting document updated.');
    }

    /** @return array{0: TravelDetail} */
    protected function authorizeDocumentUpdate(Request $request, Application $application): array
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);
        abort_unless($application->student_id === $request->user()->id, 403);

        $detail = TravelDetail::where('application_id', $application->id)->firstOrFail();
        abort_unless($detail->is_international, 403);

        return [$detail];
    }

    /**
     * The applicant, an admin, whoever holds the stage it currently sits on,
     * or anyone who has already decided on it. Mirrors
     * ApplicationDocument::visibleTo() in Core, kept local rather than
     * reused because that method is about one document, not the application.
     */
    protected function visibleTo(Application $application, User $user): bool
    {
        return $application->student_id === $user->id
            || $user->hasRole(Role::ADMIN)
            || $application->currentStage()?->role === $user->role
            || $application->history()->where('approver_id', $user->id)->exists();
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        // One query for all the detail rows rather than one per application.
        $details = TravelDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        $taPlans = TravelTaPlan::with('dates')
            ->whereIn('application_id', $details->where('is_ga_gra', true)->pluck('application_id'))
            ->get()
            ->keyBy('application_id');

        return view('norhanis::travel.queue', $queue + ['details' => $details, 'taPlans' => $taPlans]);
    }
}
