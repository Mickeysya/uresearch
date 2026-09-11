<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicationController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'publication';
    }

    public function create()
    {
        return view('norhanis::publication.form');
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        // The Letter of Undertaking is only mandatory when the student says
        // it is required — the checkbox drives the file rule server-side, so
        // a student cannot skip it by simply not ticking the box either
        // (unticked means the file is genuinely optional).
        $requiresLou = $request->boolean('requires_letter_of_undertaking');

        $data = $request->validate([
            'conference_or_journal_name' => ['required', 'string', 'max:255'],
            'publication_title' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:150'],
            'funding_amount_requested' => ['required', 'numeric', 'min:0'],
            'requires_letter_of_undertaking' => ['nullable', 'boolean'],
            'authors' => ['required', 'array', 'min:1'],
            'authors.*.name' => ['required', 'string', 'max:150'],
            'authors.*.is_corresponding_author' => ['nullable', 'boolean'],
            'letter_of_undertaking' => DocumentStore::rules($requiresLou),
        ], [
            'authors.required' => 'Add at least one author.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $requiresLou, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $publicationDetail = PublicationDetail::create([
                'application_id' => $application->id,
                'conference_or_journal_name' => $data['conference_or_journal_name'],
                'publication_title' => $data['publication_title'],
                'event_date' => $data['event_date'],
                'location' => $data['location'] ?? null,
                'funding_amount_requested' => $data['funding_amount_requested'],
                'requires_letter_of_undertaking' => $requiresLou,
            ]);

            foreach ($data['authors'] as $author) {
                $publicationDetail->authors()->create([
                    'name' => $author['name'],
                    'is_corresponding_author' => ! empty($author['is_corresponding_author']),
                ]);
            }

            if ($request->hasFile('letter_of_undertaking')) {
                $documents->attach($application, $request->file('letter_of_undertaking'), 'Letter of Undertaking');
            }

            // Hands the application to the first approver in the chain.
            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Publication application #{$application->id} submitted. Your supervisor has been notified.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        // One query for all publication rows, eager-loading authors so the
        // queue view can list each publication's authors without an N+1 query.
        $details = PublicationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('authors')
            ->get()
            ->keyBy('application_id');

        return view('norhanis::publication.queue', $queue + ['details' => $details]);
    }
}
