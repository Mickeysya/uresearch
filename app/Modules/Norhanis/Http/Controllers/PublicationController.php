<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use App\Modules\Norhanis\Models\PublicationMemo;
use App\Modules\Norhanis\Support\PublicationSignOffs;
use App\Modules\Norhanis\Support\SignatureImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicationController extends Controller
{
    use ApprovesApplications {
        decide as protected decideApplication;
    }

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
        'authorship_contribution_form' => 'Authorship Contribution Form',
        'manuscript' => PublicationSignOffs::MANUSCRIPT,
    ];

    /** Above this many authors, the Authorship Contribution Form stops being optional. */
    protected const AUTHORSHIP_CONTRIBUTION_THRESHOLD = 5;

    protected function moduleKey(): string
    {
        return 'publication';
    }

    public function create(Request $request)
    {
        return view('norhanis::publication.form', [
            // Applications already filed that are still waiting on authors or
            // reviewers, so the student can get back to their sign-off page.
            'awaitingSignOffs' => Application::for($this->moduleKey())
                ->where('student_id', $request->user()->id)
                ->where('status', Application::STATUS_DRAFT)
                ->latest('id')
                ->get(),
            'requestTypes' => PublicationDetail::requestTypes(),
            'conferenceScopes' => PublicationDetail::conferenceScopes(),
            'cgsCap' => PublicationMemo::CGS_CAP,
            'supervisor' => $request->user()->supervisor,
            'authorshipContributionThreshold' => self::AUTHORSHIP_CONTRIBUTION_THRESHOLD,
        ]);
    }

    public function store(Request $request, DocumentStore $documents, PublicationSignOffs $signOffs)
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

        // Likewise read early: the publication type decides how many internal
        // reviewers must be named. Anything that is not a string compares
        // false below and falls back to two, and validate() then rejects the
        // bad value itself.
        $reviewersRequired = PublicationDetail::reviewersRequired(
            is_string($request->input('type_of_request')) ? $request->input('type_of_request') : null,
            is_string($request->input('conference_scope')) ? $request->input('conference_scope') : null,
        );

        // And the fee, in ringgit, decides whether a split budget memo is
        // owed: the CGS fund covers up to the cap and a grant pays the rest.
        // A fee in another currency is compared by the ringgit amount the
        // student gives for it.
        $feeMyr = $request->input('currency_type') === 'MYR'
            ? $request->input('conference_journal_fee')
            : $request->input('fee_in_myr');
        $needsMemo = is_numeric($feeMyr) && PublicationMemo::neededFor((float) $feeMyr);

        // The memo is FROM the student's supervisor. Only a student with no
        // supervisor on record is asked to name one.
        $supervisor = $request->user()->supervisor;
        $memoRules = ! $needsMemo ? [] : PublicationMemoController::memoRules('memo.') + ($supervisor ? [] : [
            'memo.supervisor_name' => ['required', 'string', 'max:150'],
            'memo.supervisor_email' => ['required', 'email', 'max:255'],
        ]);

        // The student signs the Authorship Certification on the form itself;
        // their co-authors are emailed a link afterwards.
        $signature = SignatureImage::capture($request, 'applicant_signature');

        $data = $request->validate([
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
            'fee_in_myr' => ['nullable', 'required_unless:currency_type,MYR', 'numeric', 'min:0'],
            ...$memoRules,
            'cost_centre' => ['required', 'string', 'max:100'],
            'wants_letter_of_undertaking' => ['nullable', 'boolean'],
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
            'manuscript' => DocumentStore::rules(true),
            // The Authorship Contribution Form is only needed above five
            // authors. The response and the corrected paper are
            // for comments the paper already has when it is filed; an internal
            // reviewer's corrections are answered later, on the sign-off page.
            'invoice' => DocumentStore::rules(true),
            'index_proof' => DocumentStore::rules(true),
            'turnitin_report' => DocumentStore::rules(true),
            'letter_of_acceptance' => DocumentStore::rules(true),
            'conference_journal_details' => DocumentStore::rules(true),
            'response_to_reviewer_comment' => DocumentStore::rules(),
            'corrected_conference_journal_paper' => DocumentStore::rules(),
            'authorship_contribution_form' => DocumentStore::rules($requiresAuthorshipContribution),
        ], [
            'conference_end_date.after_or_equal' => 'The end date must fall on or after the start date.',
            'fee_in_myr.required_unless' => 'Give the fee in ringgit, so the system can tell whether it is above what the CGS fund covers.',
            'memo.*.required' => 'This is needed for the split budget memo, because the fee is above RM'.number_format(PublicationMemo::CGS_CAP).'.',
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
            'authorship_contribution_form.required' => 'The Authorship Contribution Form is required when more than '.self::AUTHORSHIP_CONTRIBUTION_THRESHOLD.' authors are listed.',
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

        $application = DB::transaction(function () use ($request, $data, $documents, $signOffs, $reviewers, $signature, $isApplicant, $needsMemo, $feeMyr, $supervisor) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

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
                'wants_letter_of_undertaking' => $request->boolean('wants_letter_of_undertaking'),
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

            if ($needsMemo) {
                $publicationDetail->memo()->create([
                    'supervisor_name' => $supervisor?->name ?? $data['memo']['supervisor_name'],
                    'supervisor_email' => strtolower($supervisor?->email ?? $data['memo']['supervisor_email']),
                    'supervisor_department' => $supervisor?->department,
                    'head_email' => strtolower($data['memo']['head_email']),
                ] + PublicationMemo::split((float) $feeMyr) + $data['memo']);
            }

            foreach ($reviewers as $index => $reviewer) {
                $publicationDetail->evaluations()->create([
                    'position' => $index + 1,
                    'reviewer_name' => $reviewer['name'],
                    'reviewer_email' => strtolower($reviewer['email']),
                ]);
            }

            foreach (self::DOCUMENT_FIELDS as $field => $label) {
                if ($request->hasFile($field)) {
                    $documents->attach($application, $request->file($field), $label);
                }
            }

            // Left as a draft. It is handed to the first approver by
            // PublicationSignOffs::finishIfComplete(), once every author has
            // signed and every reviewer's evaluation is in.
            return $application;
        });

        // After the transaction, so nobody is emailed about rows that a
        // rollback then removes.
        $detail = PublicationDetail::where('application_id', $application->id)
            ->with('authors', 'activeEvaluations', 'memo')
            ->firstOrFail();

        if ($detail->memo) {
            $signOffs->inviteMemoSigner($detail->memo, $detail, $request->user()->name);
        }

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

    /**
     * The shared decide(), plus one thing: the memo is addressed TO the
     * Senior Director, so their final approval is the answer to it, and the
     * memo is filed again carrying that approval.
     */
    public function decide(Request $request, Application $application, WorkflowEngine $engine): RedirectResponse
    {
        $response = $this->decideApplication($request, $application, $engine);

        $application->refresh();

        if ($application->module_type === $this->moduleKey() && $application->status === Application::STATUS_APPROVED
            && ! $application->documents()->where('doc_type', 'Approved Memo for Utilizing Grant')->exists()) {
            // The approval already stands. A PDF that fails to generate
            // must not turn it into an error page for the approver.
            try {
                app(PublicationSignOffs::class)->fileApprovedMemo($application);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        // One query for all publication rows, eager-loading authors and
        // evaluations so the queue view can list them without an N+1 query.
        $details = PublicationDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('authors', 'evaluations', 'memo')
            ->get()
            ->keyBy('application_id');

        return view('norhanis::publication.queue', $queue + ['details' => $details]);
    }
}
