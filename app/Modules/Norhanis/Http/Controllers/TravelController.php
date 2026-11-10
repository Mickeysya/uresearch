<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Norhanis\Http\Controllers\Concerns\ApprovesAndNotifies;
use App\Modules\Norhanis\Http\Controllers\Concerns\SavesDrafts;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Support\ApproverNotifier;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Models\TravelTaPlan;
use App\Modules\Norhanis\Support\StageDurations;
use App\Modules\Norhanis\Support\TaStatus;
use App\Modules\Norhanis\Workflows\TravelWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TravelController extends Controller
{
    use ApprovesAndNotifies;
    use SavesDrafts;

    protected function moduleKey(): string
    {
        return 'travel';
    }

    protected function draftFields(): array
    {
        return [
            'type_of_request', 'other_request_specify', 'is_international', 'travel_start_date', 'travel_end_date',
            'reason_for_travel', 'destination_address', 'cost_centre', 'contact_person_name', 'contact_person_no',
            'contact_person_email', 'is_ga_gra', 'ta_hours_completed', 'ta_hours_during_travel',
            'ta_management_method', 'ta_plan_dates',
        ];
    }

    protected function draftDocuments(): array
    {
        return ['supporting_document' => 'Supporting Document'];
    }

    public function create(Request $request)
    {
        return $this->form($request, null);
    }

    protected function form(Request $request, ?Application $draft)
    {
        $workflow = app(TravelWorkflow::class);
        $durations = app(StageDurations::class);

        // How long approval usually takes on each route, and so the earliest
        // a decision should arrive if submitted now. The form warns when the
        // trip starts before that -- a warning, never a block, because an
        // urgent trip is sometimes legitimate.
        $leadTimes = [];

        foreach ($workflow->routes() as $route => $stages) {
            $days = $durations->routeDays($this->moduleKey(), $stages);
            $leadTimes[$route] = [
                'days' => $days,
                'expected' => StageDurations::addWorkingDays(now(), $days)->toDateString(),
            ];
        }

        return view('norhanis::travel.form', [
            'requestTypes' => TravelDetail::requestTypes(),
            'leadTimes' => $leadTimes,
            'ta' => app(TaStatus::class)->for($request->user()),
            'taHoursRequired' => (int) config('norhanis.ta.hours_required'),
        ] + $this->draftViewData($request, $draft));
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents, ApproverNotifier $notifier)
    {
        $draft = $this->submittingDraft($request);
        $removing = $this->draftRemoving($request, $draft);

        // Whether the student is a GA/GRA, and their TA hours this semester,
        // come from the GA/GRA module when it can say -- never from the form,
        // which could claim anything. Only when nothing answers does the
        // student's own answer stand.
        $ta = app(TaStatus::class)->for($request->user());
        if ($ta['known']) {
            $request->merge(['is_ga_gra' => $ta['type'] ? 'yes' : 'no']);

            if ($ta['type']) {
                // Past the semester's requirement is simply "done".
                $request->merge(['ta_hours_completed' => min($ta['hoursCompleted'], (float) config('norhanis.ta.hours_required'))]);
            }
        }

        $data = $this->validateWithDocuments($request, $draft, $removing, [
            'type_of_request' => ['required', 'in:'.implode(',', array_keys(TravelDetail::requestTypes()))],
            'other_request_specify' => ['nullable', 'string', 'max:255', 'required_if:type_of_request,others'],
            'travel_start_date' => ['required', 'date', 'after_or_equal:today'],
            'travel_end_date' => ['required', 'date', 'after_or_equal:travel_start_date'],
            'reason_for_travel' => ['required', 'string', 'max:255'],
            'destination_address' => ['required', 'string', 'max:255'],
            // Chosen on the form (Local / International), no longer a tick
            // box left unticked by default: it decides the final approver.
            'is_international' => ['required', 'in:0,1'],
            'cost_centre' => ['nullable', 'string', 'max:100'],
            'contact_person_name' => ['nullable', 'string', 'max:150'],
            'contact_person_no' => ['nullable', 'string', 'max:30'],
            'contact_person_email' => ['required', 'email', 'max:150'],
            'is_ga_gra' => ['required', 'in:yes,no'],
            'ta_hours_completed' => ['required_if:is_ga_gra,yes', 'numeric', 'min:0', 'max:84'],
            'ta_hours_during_travel' => ['required_if:is_ga_gra,yes', 'numeric', 'min:0'],
            'ta_management_method' => ['required_if:is_ga_gra,yes', 'in:'.implode(',', array_keys(TravelTaPlan::managementMethods()))],
            'ta_plan_dates' => ['required_if:ta_management_method,'.implode(',', TravelTaPlan::methodsNeedingDates()), 'array'],
            'ta_plan_dates.*' => ['date'],
        ], [
            'travel_start_date.after_or_equal' => 'Travel cannot start in the past.',
            'travel_end_date.after_or_equal' => 'The end date must be on or after the start date.',
            'other_request_specify.required_if' => 'Please describe the request when choosing "Others".',
            'is_international.required' => 'Choose whether the travel is local or international.',
            'supporting_document.required' => 'Upload the supporting document.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents, $draft, $removing) {
            // Submitting a saved draft finishes that application rather than
            // starting a second one.
            $application = $this->draftOrNewApplication($request, $draft, $removing);

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
                'cost_centre' => $data['cost_centre'] ?? null,
                'is_international' => (string) $data['is_international'] === '1',
                'contact_person_name' => $data['contact_person_name'] ?? null,
                'contact_person_no' => $data['contact_person_no'] ?? null,
                'contact_person_email' => $data['contact_person_email'],
                'is_ga_gra' => $isGaGra,
            ]);

            if ($isGaGra) {
                $taPlan = TravelTaPlan::create([
                    'application_id' => $application->id,
                    'total_hours_required' => (int) config('norhanis.ta.hours_required'),
                    'hours_completed' => $data['ta_hours_completed'],
                    'hours_during_travel' => $data['ta_hours_during_travel'],
                    'management_method' => $data['ta_management_method'],
                ]);

                foreach (array_filter($data['ta_plan_dates'] ?? []) as $date) {
                    $taPlan->dates()->create(['date' => $date]);
                }
            }

            $this->attachDraftFiles($request, $application, $documents);

            // Hands the application to the first approver in the chain.
            return $engine->submit($application);
        });

        $notifier->submitted($application);

        return redirect()
            ->route('applications.index')
            ->with('status', "Travel application #{$application->id} submitted. It is now waiting for your supervisor's decision.");
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

        // The supporting document is fixed once submitted: there is no
        // replacing it afterwards, local or international.
        return view('norhanis::travel.show', compact('application', 'detail', 'taPlan'));
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
