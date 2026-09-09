<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GaExtensionController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'ga_extension';
    }

    public function create()
    {
        return view('nureen::ga_extension.form');
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $request->validate([
            'current_end_date' => ['required', 'date'],
            'requested_new_end_date' => ['required', 'date', 'after:current_end_date'],
            'reason_for_extension' => ['required', 'string', 'min:20', 'max:2000'],
            // The scope calls for document completeness validation before an
            // application may reach CGS, so this one is required, not optional.
            'supporting_document' => DocumentStore::rules(required: true),
        ], [
            'requested_new_end_date.after' => 'The new end date must be later than the current one.',
            'reason_for_extension.min' => 'Please give CGS at least a sentence or two of justification.',
            'supporting_document.required' => 'A supporting document is required for GA extensions.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'submitted_by_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            GaExtensionDetail::create([
                'application_id' => $application->id,
                'current_end_date' => $data['current_end_date'],
                'requested_new_end_date' => $data['requested_new_end_date'],
                'reason_for_extension' => $data['reason_for_extension'],
            ]);

            $documents->attach($application, $request->file('supporting_document'), 'Supporting Document');

            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "GA Extension application #{$application->id} submitted.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = GaExtensionDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('nureen::ga_extension.queue', $queue + ['details' => $details]);
    }
}
