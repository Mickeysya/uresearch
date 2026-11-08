<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Norhanis\Http\Controllers\Concerns\ApprovesAndNotifies;
use App\Modules\Norhanis\Http\Controllers\Concerns\SavesDrafts;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use App\Modules\Norhanis\Models\SavedDraft;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use App\Modules\Norhanis\Support\SignatureImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicationController extends Controller
{
    use ApprovesAndNotifies;
    use SavesDrafts;

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
        'response_to_reviewer_comment' => 'Response to Reviewer Comment',
        'corrected_conference_journal_paper' => 'Corrected Conference/Journal Paper',
        'manuscript' => PublicationSignOffs::MANUSCRIPT,
    ];

    /** The paper itself stays one file: reviewers and co-authors read that one copy. */
    protected function singleFileDocuments(): array
    {
        return ['manuscript'];
    }

    protected function moduleKey(): string
    {
        return 'publication';
    }

    protected function draftFields(): array
    {
        return [
            'type_of_request', 'conference_scope', 'title_of_paper', 'title_of_conference_journal',
            'organizer_publisher', 'venue', 'conference_start_date', 'conference_end_date',
            'conference_journal_fee', 'currency_type', 'cost_centre', 'cost_centre_other',
            'authors', 'applicant_conflict_disclosure', 'reviewers',
        ];
    }

    protected function draftDocuments(): array
    {
        return self::DOCUMENT_FIELDS;
    }

    public function create(Request $request)
    {
        return $this->form($request, null);
    }

    protected function form(Request $request, ?Application $draft)
    {
        return view('norhanis::publication.form', [
            // Applications already filed that are still waiting on authors or
            // reviewers, so the student can get back to their sign-off page.
            // A saved draft is a draft too, but nobody has been asked to sign it.
            'awaitingSignOffs' => Application::for($this->moduleKey())
                ->where('student_id', $request->user()->id)
                ->where('status', Application::STATUS_DRAFT)
                ->whereNotIn('id', SavedDraft::select('application_id'))
                ->latest('id')
                ->get(),
            'requestTypes' => PublicationDetail::requestTypes(),
            'conferenceScopes' => PublicationDetail::conferenceScopes(),
            'costCentres' => PublicationDetail::COST_CENTRES,
        ] + $this->draftViewData($request, $draft));
    }

    public function store(Request $request, DocumentStore $documents, PublicationSignOffs $signOffs)
    {
        $draft = $this->submittingDraft($request);
        $removing = $this->draftRemoving($request, $draft);

        // Read before validate(): the publication type decides how many internal
        // reviewers must be named. Anything that is not a string compares
        // false below and falls back to two, and validate() then rejects the
        // bad value itself.
        $reviewersRequired = PublicationDetail::reviewersRequired(
            is_string($request->input('type_of_request')) ? $request->input('type_of_request') : null,
            is_string($request->input('conference_scope')) ? $request->input('conference_scope') : null,
        );

        // The student signs the Authorship Certification on the form itself;
        // their co-authors are emailed a link afterwards.
        $signature = SignatureImage::capture($request, 'applicant_signature');

        $data = $this->validateWithDocuments($request, $draft, $removing, [
            'type_of_request' => ['required', 'in:'.implode(',', array_keys(PublicationDetail::requestTypes()))],
            'conference_scope' => [
                'nullable', 'required_if:type_of_request,publication_conference',
                'in:'.implode(',', array_keys(PublicationDetail::conferenceScopes())),
            ],
            'title_of_paper' => ['required', 'string', 'max:255'],
            'title_of_conference_journal' => ['required', 'string', 'max:255'],
            'organizer_publisher' => ['required', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'conference_journal_fee' => ['required', 'numeric', 'min:0'],
            'currency_type' => ['required', 'string', 'max:10'],
            // One choice from the list agreed with CGS. A split memo is not
            // handled here: CGS emails the template and the supervisor
            // completes it outside UResearch.
            'cost_centre' => ['required', 'in:'.implode(',', array_keys(PublicationDetail::COST_CENTRES))],
            'cost_centre_other' => ['nullable', 'required_if:cost_centre,others', 'string', 'max:150'],
            'conference_start_date' => ['required', 'date'],
            'conference_end_date' => ['required', 'date', 'after_or_equal:conference_start_date'],
            'authors' => ['required', 'array', 'min:1', 'max:50'],
            'authors.*.author_name' => ['required', 'string', 'max:150'],
            // Each author signs from a link sent here, so one address cannot
            // stand for two authors.
            'authors.*.email' => ['required', 'email', 'max:255', 'distinct:ignore_case'],
            'authors.*.designation' => ['nullable', 'string', 'max:150'],
            'authors.*.organisation' => ['nullable', 'string', 'max:150'],
            'authors.*.role_contribution' => ['nullable', 'string', 'max:150'],
            'applicant_certifies' => ['accepted'],
            'applicant_signature' => ['required'],
            'applicant_conflict_disclosure' => ['nullable', 'string', 'max:2000'],
            // One internal reviewer per Paper Evaluation Sheet owed. Each is
            // emailed a link, so one address cannot stand for two reviewers.
            'reviewers' => ['required', 'array', 'min:'.$reviewersRequired],
            'reviewers.*.name' => ['required', 'string', 'max:150'],
            'reviewers.*.email' => [
                'required', 'email', 'max:255', 'distinct:ignore_case',
                'ends_with:'.PublicationEvaluation::REVIEWER_EMAIL_DOMAIN,
            ],
        ], [
            'conference_end_date.after_or_equal' => 'The end date must fall on or after the start date.',
            'cost_centre.required' => 'Choose the cost centre to be utilized.',
            'cost_centre_other.required_if' => 'Say which cost centre, as you chose "Others".',
            'authors.required' => 'Add at least one author.',
            'authors.max' => 'A publication cannot list more than 50 authors.',
            'authors.*.email.required' => 'Every author needs an email address to receive their signing link.',
            'authors.*.email.distinct' => 'Each author needs their own email address.',
            'applicant_certifies.accepted' => 'Tick the box to certify the authorship.',
            'applicant_signature.required' => 'Sign in the box, or upload an image of your signature.',
            'conference_scope.required_if' => 'Select whether the conference is local or international.',
            'reviewers.required' => 'Name your internal reviewers.',
            'reviewers.min' => $reviewersRequired === 3
                ? 'An international conference needs three internal reviewers.'
                : 'Name two internal reviewers.',
            'reviewers.*.email.ends_with' => 'An internal reviewer must have a UTP email address ('.PublicationEvaluation::REVIEWER_EMAIL_DOMAIN.').',
            'reviewers.*.email.distinct' => 'Each reviewer must be a different person.',
            'manuscript.required' => 'Upload the paper for your reviewers to read.',
        ]);

        // The student's own signature has to land on one of the author rows,
        // and the only reliable way to tell which is the email they log in with.
        $ownEmail = strtolower($request->user()->email);
        $isApplicant = fn (array $author) => strtolower($author['email']) === $ownEmail;

        if (! collect($data['authors'])->contains($isApplicant)) {
            throw ValidationException::withMessages([
                'authors' => "List yourself among the authors, using your own email address ({$request->user()->email}).",
            ]);
        }

        // A reviewer posted beyond the number owed is not part of the
        // application, so it is not kept.
        $reviewers = array_slice(array_values($data['reviewers']), 0, $reviewersRequired);

        PublicationSignOffs::assertEligibleReviewers(
            array_column($data['authors'], 'email'),
            array_column($reviewers, 'email'),
            'reviewers',
        );

        $application = DB::transaction(function () use ($request, $data, $documents, $signOffs, $reviewers, $signature, $isApplicant, $draft, $removing) {
            // Submitting a saved draft finishes that application rather than
            // starting a second one; from here on it waits for sign-offs.
            $application = $this->draftOrNewApplication($request, $draft, $removing);

            $publicationDetail = PublicationDetail::create([
                'application_id' => $application->id,
                'type_of_request' => $data['type_of_request'],
                // A journal has no scope, whatever the form posted.
                'conference_scope' => $data['type_of_request'] === 'publication_conference'
                    ? $data['conference_scope']
                    : null,
                'title_of_paper' => $data['title_of_paper'],
                'title_of_conference_journal' => $data['title_of_conference_journal'],
                'organizer_publisher' => $data['organizer_publisher'],
                'venue' => $data['venue'] ?? null,
                'conference_journal_fee' => $data['conference_journal_fee'],
                'currency_type' => $data['currency_type'],
                'cost_centre' => $data['cost_centre'],
                'cost_centre_other' => $data['cost_centre'] === 'others' ? $data['cost_centre_other'] : null,
                // Kept for reports: the LOU is now one of the cost centre choices.
                'wants_letter_of_undertaking' => $data['cost_centre'] === 'cgs_letter_of_undertaking',
                'conference_start_date' => $data['conference_start_date'],
                'conference_end_date' => $data['conference_end_date'],
            ]);

            foreach ($data['authors'] as $author) {
                $row = $publicationDetail->authors()->create([
                    'author_name' => $author['author_name'],
                    'email' => strtolower($author['email']),
                    'designation' => $author['designation'] ?? null,
                    'organisation' => $author['organisation'] ?? null,
                    'role_contribution' => $author['role_contribution'] ?? null,
                ]);

                if ($isApplicant($author)) {
                    $signOffs->sign($row, $signature, $data['applicant_conflict_disclosure'] ?? null, $request->ip());
                }
            }

            foreach ($reviewers as $index => $reviewer) {
                $publicationDetail->evaluations()->create([
                    'position' => $index + 1,
                    'reviewer_name' => $reviewer['name'],
                    'reviewer_email' => strtolower($reviewer['email']),
                ]);
            }

            $this->attachDraftFiles($request, $application, $documents);

            // Left as a draft. It is handed to the first approver by
            // PublicationSignOffs::finishIfComplete(), once every author has
            // signed and every reviewer's evaluation is in.
            return $application;
        });

        // After the transaction, so nobody is emailed about rows that a
        // rollback then removes.
        $detail = PublicationDetail::where('application_id', $application->id)
            ->with('authors', 'activeEvaluations')
            ->firstOrFail();

        foreach ($detail->authors->reject(fn (PublicationAuthor $author) => $author->hasSigned()) as $author) {
            $signOffs->invite($author, $detail, $request->user()->name);
        }

        foreach ($detail->activeEvaluations as $evaluation) {
            $signOffs->inviteReviewer($evaluation, $detail, $request->user()->name);
        }

        return redirect()
            ->route('publication.sign-offs', $application)
            ->with('status', "Publication application #{$application->id} saved. Everyone who has to sign "
                .'has been emailed a link. It goes to your supervisor once every one of them has signed.');
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        // One query for all publication rows, eager-loading authors and
        // evaluations so the queue view can list them without an N+1 query.
        $details = PublicationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('authors', 'evaluations')
            ->get()
            ->keyBy('application_id');

        return view('norhanis::publication.queue', $queue + ['details' => $details]);
    }
}
