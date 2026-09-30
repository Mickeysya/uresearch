<?php

namespace App\Modules\Jason\Mail;

use App\Modules\Core\Models\Application;
use App\Modules\Jason\Models\AppointmentExaminer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks an appointed examiner to sign the Confirmation of Correction.
 *
 * A plain Mailable rather than a Notification, for the same reason
 * AppointmentLetterMail is one: an examiner has no account to notify. The
 * link it carries is a temporary signed URL, so it works without a login
 * and stops working on its own.
 */
class ExaminerSignatureRequest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        protected Application $application,
        protected AppointmentExaminer $examiner,
        protected string $url,
        protected string $candidate,
        protected string $thesisTitle,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Signature required: Confirmation of Correction to Thesis, {$this->candidate}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'jason::mail.examiner-signature-request',
            with: [
                'examinerName' => $this->examiner->examiner_name,
                'candidate' => $this->candidate,
                'thesisTitle' => $this->thesisTitle,
                'url' => $this->url,
                'applicationId' => $this->application->id,
            ],
        );
    }
}
