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

    /**
     * The named upload fields on the form, each attached under its own
     * label — see DOCUMENTS in the module spec — so CGS staff can tell them
     * apart later instead of seeing a pile of unlabeled attachments.
     *
     * @var array<string, string>
     */
    protected const DOCUMENT_FIELDS = [
        'invoice' => 'Invoice',
        'index_proof' => 'Index Proof',
        'turnitin_report' => 'Turnitin Report',
        'letter_of_acceptance' => 'Letter of Acceptance',
        'conference_journal_details' => 'Conference/Journal Details',
        'authorship_certification_form' => 'Authorship Certification Form',
        'response_to_reviewer_comment' => 'Response to Reviewer Comment',
        'approved_memo_for_utilizing_grant' => 'Approved Memo for Utilizing Grant',
        'corrected_conference_journal_paper' => 'Corrected Conference/Journal Paper',
        'authorship_contribution_form' => 'Authorship Contribution Form',
        'paper_evaluation_sheet' => 'Paper Evaluation Sheet',
    ];

    /** Above this many authors, the Authorship Contribution Form stops being optional. */
    protected const AUTHORSHIP_CONTRIBUTION_THRESHOLD = 5;

    protected function moduleKey(): string
    {
        return 'publication';
    }

    public function create()
    {
        return view('norhanis::publication.form', [
            'requestTypes' => PublicationDetail::requestTypes(),
        ]);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        // Read before validate() so the author count can drive whether the
        // Authorship Contribution Form is required — the same way
        // TravelController derives duration_days instead of trusting the
        // client, this can't be a client-side-only rule since the rows come
        // from a JS-managed repeating section the student's browser controls.
        // Because it runs first, `authors` is still whatever was posted: the
        // (array) cast is what stops a scalar (`authors=foo`) throwing a
        // TypeError out of count() and returning a blank 500. Cast, it
        // becomes a one-element array and fails the `array` rule properly.
        $authorCount = count((array) $request->input('authors', []));
        $requiresAuthorshipContribution = $authorCount > self::AUTHORSHIP_CONTRIBUTION_THRESHOLD;

        $data = $request->validate([
            'type_of_request' => ['required', 'in:'.implode(',', array_keys(PublicationDetail::requestTypes()))],
            'title_of_paper' => ['required', 'string', 'max:255'],
            'title_of_conference_journal' => ['required', 'string', 'max:255'],
            'organizer_publisher' => ['required', 'string', 'max:255'],
            'conference_journal_fee' => ['required', 'numeric', 'min:0'],
            'currency_type' => ['required', 'string', 'max:10'],
            'cost_centre' => ['required', 'string', 'max:100'],
            'wants_letter_of_undertaking' => ['nullable', 'boolean'],
            'conference_start_date' => ['required', 'date'],
            'conference_end_date' => ['required', 'date', 'after_or_equal:conference_start_date'],
            'authors' => ['required', 'array', 'min:1', 'max:50'],
            'authors.*.author_name' => ['required', 'string', 'max:150'],
            'authors.*.designation' => ['nullable', 'string', 'max:150'],
            'authors.*.organisation' => ['nullable', 'string', 'max:150'],
            'authors.*.role_contribution' => ['nullable', 'string', 'max:150'],
            'invoice' => DocumentStore::rules(),
            'index_proof' => DocumentStore::rules(),
            'turnitin_report' => DocumentStore::rules(),
            'letter_of_acceptance' => DocumentStore::rules(),
            'conference_journal_details' => DocumentStore::rules(),
            'authorship_certification_form' => DocumentStore::rules(),
            'response_to_reviewer_comment' => DocumentStore::rules(),
            'approved_memo_for_utilizing_grant' => DocumentStore::rules(),
            'corrected_conference_journal_paper' => DocumentStore::rules(),
            'authorship_contribution_form' => DocumentStore::rules($requiresAuthorshipContribution),
            'paper_evaluation_sheet' => DocumentStore::rules(),
        ], [
            'conference_end_date.after_or_equal' => 'The end date must fall on or after the start date.',
            'authors.required' => 'Add at least one author.',
            'authors.max' => 'A publication cannot list more than 50 authors.',
            'authorship_contribution_form.required' => 'The Authorship Contribution Form is required when more than '.self::AUTHORSHIP_CONTRIBUTION_THRESHOLD.' authors are listed.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            $publicationDetail = PublicationDetail::create([
                'application_id' => $application->id,
                'type_of_request' => $data['type_of_request'],
                'title_of_paper' => $data['title_of_paper'],
                'title_of_conference_journal' => $data['title_of_conference_journal'],
                'organizer_publisher' => $data['organizer_publisher'],
                'conference_journal_fee' => $data['conference_journal_fee'],
                'currency_type' => $data['currency_type'],
                'cost_centre' => $data['cost_centre'],
                'wants_letter_of_undertaking' => $request->boolean('wants_letter_of_undertaking'),
                'conference_start_date' => $data['conference_start_date'],
                'conference_end_date' => $data['conference_end_date'],
            ]);

            foreach ($data['authors'] as $author) {
                $publicationDetail->authors()->create([
                    'author_name' => $author['author_name'],
                    'designation' => $author['designation'] ?? null,
                    'organisation' => $author['organisation'] ?? null,
                    'role_contribution' => $author['role_contribution'] ?? null,
                ]);
            }

            foreach (self::DOCUMENT_FIELDS as $field => $label) {
                if ($request->hasFile($field)) {
                    $documents->attach($application, $request->file($field), $label);
                }
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
