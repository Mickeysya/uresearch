<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\TravelDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TravelController extends Controller
{
    use ApprovesApplications;

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
            'supporting_document' => DocumentStore::rules(),
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
            ]);

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

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        // One query for all the detail rows rather than one per application.
        $details = TravelDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('norhanis::travel.queue', $queue + ['details' => $details]);
    }
}
