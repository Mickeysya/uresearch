<?php

namespace App\Modules\Nureen\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UnauthorizedException;

class GaExtensionController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'ga_extension';
    }

    public function create(Request $request)
    {
        return view('nureen::ga_extension.form', [
            'student' => $request->user(),
            'requiredDocuments' => config('nureen.ga_extension.required_documents'),
            'additionalFields' => config('nureen.ga_extension.additional_fields'),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $requiredDocuments = config('nureen.ga_extension.required_documents');
        $additionalFields = config('nureen.ga_extension.additional_fields');

        $rules = [
            'current_end_date' => ['required', 'date'],
            'requested_new_end_date' => ['required', 'date', 'after:current_end_date'],
            'reason_for_extension' => ['required', 'string', 'min:20', 'max:2000'],
        ];

        $messages = [
            'requested_new_end_date.after' => 'The new end date must be later than the current one.',
            'reason_for_extension.min' => 'Please give CGS at least a sentence or two of justification.',
        ];

        // The scope calls for document completeness validation before an
        // application may reach CGS, so each required type is enforced
        // separately rather than as one undifferentiated upload -- see
        // DocumentStore::manyRules()/manyMessages() for the shared
        // count/size/type limits and their matching messages.
        foreach ($requiredDocuments as $field => $label) {
            $rules += DocumentStore::manyRules($field);
            $messages["{$field}.required"] = "Attach at least one file for \"{$label}\".";
            $messages += DocumentStore::manyMessages($field);
        }

        // Provisional fields from config('nureen.ga_extension.additional_fields')
        // -- empty today, so this adds nothing until the real CGS form is
        // confirmed and a field is declared there.
        foreach ($additionalFields as $key => $field) {
            $rules["additional_info.{$key}"] = $this->additionalFieldRules($field);
        }

        $data = $request->validate($rules, $messages);

        $application = DB::transaction(function () use ($request, $data, $requiredDocuments, $additionalFields, $engine, $documents) {
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
                'additional_info' => $additionalFields === [] ? null : ($data['additional_info'] ?? []),
            ]);

            foreach ($requiredDocuments as $field => $label) {
                $documents->attachMany($application, $request->file($field), $label);
            }

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

        return view('nureen::ga_extension.queue', $queue + [
            'details' => $details,
            // Only CGS Staff, at the cgs_verify stage, may send an
            // incomplete application back to the supervisor rather than
            // rejecting it outright -- see returnToSupervisor().
            'canReturn' => $queue['stage']->key === 'cgs_verify',
        ]);
    }

    /**
     * Sends an application back to the supervisor instead of rejecting it,
     * from CGS Staff's desk -- a missing or wrong document does not need to
     * end the application, only go back one stage for a fix.
     *
     * Not a decide()-time check: the route is open to the whole chain and
     * WorkflowEngine::returnTo() refuses it anywhere but the cgs_verify
     * stage, the same way every other authorisation check in this app
     * belongs to the engine rather than to a middleware list. See
     * Hani\Http\Controllers\ExaminerNominationController::returnToDepartment()
     * for the pattern this follows.
     */
    public function returnToSupervisor(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        abort_unless($application->module_type === $this->moduleKey(), 404);

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'Say what is missing or needs changing — the supervisor is being asked to review it again.',
        ]);

        try {
            $engine->returnTo($application, $request->user(), 'supervisor', $data['remarks']);
        } catch (UnauthorizedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\LogicException $e) {
            return back()->with('error', 'That application is not at a stage you can send back.');
        }

        return back()->with(
            'status',
            "Application #{$application->id} sent back to the supervisor. They have been notified."
        );
    }

    /** @return array<int, string> */
    protected function additionalFieldRules(array $field): array
    {
        $type = $field['type'] ?? 'text';

        return array_filter([
            ($field['required'] ?? false) ? 'required' : 'nullable',
            match ($type) {
                'date' => 'date',
                default => 'string',
            },
            $type === 'text' ? 'max:255' : ($type === 'textarea' ? 'max:2000' : null),
        ]);
    }
}
