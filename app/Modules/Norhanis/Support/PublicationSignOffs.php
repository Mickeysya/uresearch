<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Models\PublicationEvaluation;
use App\Modules\Norhanis\Models\PublicationMemo;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationRequested;
use App\Modules\Norhanis\Notifications\MemoSignatureRequested;
use App\Modules\Norhanis\Notifications\PaperEvaluationRequested;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The sign-offs a publication application collects before it enters the
 * approval chain.
 *
 * A publication is saved as a draft, and stays one while its authors sign the
 * Authorship Certification, its internal reviewers complete their Paper
 * Evaluation Sheets and, where the fee needs one, the split budget memo is
 * signed -- all online. When the last of those is in, the application is
 * handed to WorkflowEngine::submit(), so approvers only ever see applications
 * that are already fully signed and evaluated.
 */
class PublicationSignOffs
{
    /** The paper the student uploads for the reviewers to read. */
    public const MANUSCRIPT = 'Manuscript for Review';

    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentStore $documents,
    ) {}

    /**
     * A link that works without an account. Signed over the path only, so it
     * still verifies when the portal is reached through a different host name
     * than the one the student happened to be using.
     */
    public function signedUrl(string $route, array $parameters): string
    {
        return url(URL::signedRoute($route, $parameters, absolute: false));
    }

    // ---- Authorship Certification -----------------------------------------

    /** The link an author signs from. */
    public function signingUrl(PublicationAuthor $author): string
    {
        return $this->signedUrl('publication.certify', ['author' => $author]);
    }

    /** Email an author their signing link (first time or as a reminder). */
    public function invite(PublicationAuthor $author, PublicationDetail $detail, string $studentName): void
    {
        Notification::route('mail', $author->email)->notify(new AuthorshipCertificationRequested(
            $author->author_name,
            $studentName,
            $detail->title_of_paper,
            $this->signingUrl($author),
        ));

        $author->forceFill(['invited_at' => now()])->save();
    }

    public function sign(PublicationAuthor $author, string $signature, ?string $conflict, ?string $ip): void
    {
        $author->forceFill([
            'signature_png' => $signature,
            'conflict_disclosure' => $conflict ?: null,
            'signed_at' => now(),
            'signed_ip' => $ip,
        ])->save();
    }

    // ---- Paper Evaluation -------------------------------------------------

    /** The link a reviewer evaluates from. */
    public function evaluationUrl(PublicationEvaluation $evaluation): string
    {
        return $this->signedUrl('publication.evaluate', ['evaluation' => $evaluation]);
    }

    /** Email a reviewer their evaluation link (first time or as a reminder). */
    public function inviteReviewer(PublicationEvaluation $evaluation, PublicationDetail $detail, string $studentName): void
    {
        Notification::route('mail', $evaluation->reviewer_email)->notify(new PaperEvaluationRequested(
            $evaluation->reviewer_name,
            $studentName,
            $detail->title_of_paper,
            $this->evaluationUrl($evaluation),
        ));

        $evaluation->forceFill(['invited_at' => now()])->save();
    }

    /**
     * Record a reviewer's completed sheet and file it as a PDF.
     *
     * @param  array<string, mixed>  $sheet  the validated ratings, comments and recommendation
     */
    public function recordEvaluation(PublicationEvaluation $evaluation, array $sheet, string $signature, ?string $ip): ApplicationDocument
    {
        return DB::transaction(function () use ($evaluation, $sheet, $signature, $ip) {
            $evaluation->forceFill($sheet + [
                'signature_png' => $signature,
                'signed_at' => now(),
                'signed_ip' => $ip,
            ])->save();

            $detail = $evaluation->publicationDetail()->with('authors', 'application')->firstOrFail();

            $pdf = Pdf::setOption('isFontSubsettingEnabled', true)
                ->loadView('norhanis::publication.evaluation_pdf', [
                    'application' => $detail->application,
                    'detail' => $detail,
                    'evaluation' => $evaluation,
                ]);

            return $this->storeOrFail(
                $detail->application,
                $pdf->output(),
                "paper-evaluation-{$detail->application_id}-reviewer-{$evaluation->position}.pdf",
                $evaluation->sheetLabel(),
            );
        });
    }

    public function decline(PublicationEvaluation $evaluation, string $reason): void
    {
        $evaluation->forceFill(['declined_at' => now(), 'decline_reason' => $reason])->save();
    }

    /** Ask someone else for the same position. The old row is kept on record. */
    public function replaceReviewer(PublicationEvaluation $evaluation, string $name, string $email): PublicationEvaluation
    {
        return DB::transaction(function () use ($evaluation, $name, $email) {
            $evaluation->forceFill(['replaced_at' => now()])->save();

            return PublicationEvaluation::create([
                'publication_detail_id' => $evaluation->publication_detail_id,
                'position' => $evaluation->position,
                'reviewer_name' => $name,
                'reviewer_email' => strtolower($email),
            ]);
        });
    }

    /** The student's answer to "recommended with corrections". */
    public function respond(PublicationEvaluation $evaluation, string $response): void
    {
        $evaluation->forceFill(['response' => $response, 'responded_at' => now()])->save();
    }

    /**
     * A reviewer cannot be one of the paper's authors, and the same person
     * cannot be asked twice.
     *
     * @param  array<int, string|null>  $authorEmails
     * @param  array<int, string>  $reviewerEmails
     */
    public static function assertEligibleReviewers(array $authorEmails, array $reviewerEmails, string $field): void
    {
        $authors = array_map('strtolower', array_filter($authorEmails));
        $reviewers = array_map('strtolower', $reviewerEmails);

        if (array_intersect($reviewers, $authors) !== []) {
            throw ValidationException::withMessages([
                $field => 'An author of the paper cannot be one of its reviewers.',
            ]);
        }

        if (count($reviewers) !== count(array_unique($reviewers))) {
            throw ValidationException::withMessages([
                $field => 'Each reviewer must be a different person.',
            ]);
        }
    }

    // ---- Split budget memo ------------------------------------------------

    /** The link the supervisor or the centre head signs the memo from. */
    public function memoUrl(PublicationMemo $memo, string $signer): string
    {
        return $this->signedUrl('publication.memo', ['memo' => $memo, 'signer' => $signer]);
    }

    /** Email whoever's turn it is to sign (first time or as a reminder). */
    public function inviteMemoSigner(PublicationMemo $memo, PublicationDetail $detail, string $studentName): void
    {
        $signer = $memo->awaiting();

        if ($signer === null) {
            return;
        }

        Notification::route('mail', $memo->{"{$signer}_email"})->notify(new MemoSignatureRequested(
            $memo->{"{$signer}_name"},
            $studentName,
            $detail->title_of_paper,
            number_format((float) $memo->grant_amount, 2),
            $this->memoUrl($memo, $signer),
        ));

        $memo->forceFill(["{$signer}_invited_at" => now()])->save();
    }

    /**
     * @param  array<string, string>  $corrections  grant details the supervisor put right while signing
     */
    public function signMemo(PublicationMemo $memo, string $signer, string $signature, ?string $ip, array $corrections = []): void
    {
        $memo->forceFill($corrections + [
            "{$signer}_signature_png" => $signature,
            "{$signer}_signed_at" => now(),
            "{$signer}_signed_ip" => $ip,
        ])->save();
    }

    public function declineMemo(PublicationMemo $memo, string $signer, string $reason): void
    {
        $memo->forceFill(['declined_at' => now(), 'declined_by' => $signer, 'decline_reason' => $reason])->save();
    }

    /**
     * The student's corrected memo. Every signature starts again: nobody's
     * signature may stay on a memo that changed after they signed it.
     *
     * @param  array<string, mixed>  $details
     */
    public function reviseMemo(PublicationMemo $memo, array $details): void
    {
        $reset = ['declined_at' => null, 'declined_by' => null, 'decline_reason' => null];

        foreach (array_keys(PublicationMemo::SIGNERS) as $signer) {
            $reset += [
                "{$signer}_invited_at" => null,
                "{$signer}_signature_png" => null,
                "{$signer}_signed_at" => null,
                "{$signer}_signed_ip" => null,
            ];
        }

        $memo->forceFill($details + $reset)->save();
    }

    /**
     * On final approval: file the memo again, now carrying the Senior
     * Director's approval, which is the answer to it.
     */
    public function fileApprovedMemo(Application $application): ?ApplicationDocument
    {
        $detail = PublicationDetail::where('application_id', $application->id)->with('memo')->first();

        if (! $detail?->memo) {
            return null;
        }

        return $this->storeMemo($application, $detail, 'Approved Memo for Utilizing Grant');
    }

    protected function storeMemo(Application $application, PublicationDetail $detail, string $docType): ApplicationDocument
    {
        $pdf = Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('norhanis::publication.memo_pdf', [
                'application' => $application,
                'detail' => $detail,
                'memo' => $detail->memo,
                'student' => $application->student,
                // Present only once the chain has finished in approval.
                'approval' => $application->status === Application::STATUS_APPROVED
                    ? $application->history()->with('approver')->get()->last()
                    : null,
            ]);

        return $this->storeOrFail($application, $pdf->output(), "split-budget-memo-{$application->id}.pdf", $docType);
    }

    // ---- Completion ---------------------------------------------------------

    /**
     * Once every author has signed, every reviewer's sheet is settled and any
     * memo is signed: generate the forms and put the application onto its
     * first stage. Returns the generated form, or null if something is outstanding.
     */
    public function finishIfComplete(Application $application): ?ApplicationDocument
    {
        return DB::transaction(function () use ($application) {
            // Locked, because two people signing in the same moment would
            // otherwise both see "everything is in" and submit twice.
            $application = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($application->status !== Application::STATUS_DRAFT) {
                return null;
            }

            $detail = PublicationDetail::where('application_id', $application->id)
                ->with('authors', 'activeEvaluations', 'memo')
                ->firstOrFail();

            if (! $this->isComplete($detail)) {
                return null;
            }

            if ($detail->memo) {
                $this->storeMemo($application, $detail, 'Memo Request Split Budget');
            }

            // Subsetting embeds only the letters used, not the whole font:
            // the difference between a 900 KB attachment and a small one.
            $pdf = Pdf::setOption('isFontSubsettingEnabled', true)
                ->loadView('norhanis::publication.certification_pdf', [
                    'application' => $application,
                    'detail' => $detail,
                ]);

            $form = $this->storeOrFail(
                $application,
                $pdf->output(),
                "authorship-certification-{$application->id}.pdf",
                'Authorship Certification Form',
            );

            $this->engine->submit($application);

            return $form;
        });
    }

    /** Has every author signed, is every evaluation settled, and is any memo signed? */
    public function isComplete(PublicationDetail $detail): bool
    {
        if ($detail->memo && ! $detail->memo->isSigned()) {
            return false;
        }

        if ($detail->authors->contains(fn (PublicationAuthor $author) => ! $author->hasSigned())) {
            return false;
        }

        $required = PublicationDetail::reviewersRequired($detail->type_of_request, $detail->conference_scope);

        return $detail->activeEvaluations->count() >= $required
            && $detail->activeEvaluations->every(fn (PublicationEvaluation $evaluation) => $evaluation->isSatisfied());
    }

    /**
     * storeGenerated() does not report a failed write. A sign-off without its
     * PDF must not count, so stop here: the surrounding transaction rolls
     * back and the step can simply be done again.
     */
    protected function storeOrFail(Application $application, string $contents, string $filename, string $docType): ApplicationDocument
    {
        $document = $this->documents->storeGenerated($application, $contents, $filename, $docType);

        if (! $document->exists()) {
            throw new \RuntimeException("Could not save \"{$docType}\" for application #{$application->id}.");
        }

        return $document;
    }
}
