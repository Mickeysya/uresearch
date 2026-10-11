<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\PublicationAuthor;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Notifications\AuthorshipCertificationRequested;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The sign-offs a publication application collects before it enters the
 * approval chain.
 *
 * A publication is saved as a draft, and stays one while its authors sign the
 * Authorship Certification online. When the last one signs, the form is
 * generated as a PDF and the application is handed to WorkflowEngine::submit()
 * -- so approvers only ever see applications that are already fully signed.
 */
class PublicationSignOffs
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentStore $documents,
    ) {}

    /** The link an author signs from. Works without an account. */
    public function signingUrl(PublicationAuthor $author): string
    {
        // Signed over the path only, so the link still verifies when the
        // portal is reached through a different host name than the one the
        // student happened to be using.
        return url(URL::signedRoute('publication.certify', ['author' => $author], absolute: false));
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

    /**
     * Once every author has signed: generate the certification form and put
     * the application onto its first stage. Returns the generated form, or
     * null if signatures are still outstanding.
     */
    public function finishIfComplete(Application $application): ?ApplicationDocument
    {
        return DB::transaction(function () use ($application) {
            // Locked, because two authors signing in the same moment would
            // otherwise both see "everyone has signed" and submit twice.
            $application = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($application->status !== Application::STATUS_DRAFT) {
                return null;
            }

            $detail = PublicationDetail::where('application_id', $application->id)->with('authors')->firstOrFail();

            if ($detail->authors->contains(fn (PublicationAuthor $author) => ! $author->hasSigned())) {
                return null;
            }

            // Subsetting embeds only the letters used, not the whole font:
            // the difference between a 900 KB attachment and a small one.
            $pdf = Pdf::setOption('isFontSubsettingEnabled', true)
                ->loadView('norhanis::publication.certification_pdf', [
                    'application' => $application,
                    'detail' => $detail,
                ]);

            $form = $this->documents->storeGenerated(
                $application,
                $pdf->output(),
                "authorship-certification-{$application->id}.pdf",
                'Authorship Certification Form',
            );

            // storeGenerated() does not report a failed write. Without the
            // form the application must not reach an approver, so stop here:
            // the transaction rolls back and it stays a draft, to be retried
            // the next time the student opens the sign-off page.
            if (! $form->exists()) {
                throw new \RuntimeException("Could not save the Authorship Certification Form for application #{$application->id}.");
            }

            $this->engine->submit($application);

            return $form;
        });
    }
}
