<?php

namespace App\Modules\Jason\Mail;

use App\Modules\Core\Models\Application;
use App\Modules\Jason\Models\AppointmentDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers the generated Appointment Letter PDF to the examiner.
 *
 * Every other decision email in this portal is an
 * `App\Modules\Core\Notifications\ApplicationDecided` sent to a `User`
 * Eloquent model, because every other recipient has a login. An external
 * examiner never does -- their only record in the system is a row on
 * `appointment_details` -- so this has to be a plain Mailable addressed by
 * a bare email string, dispatched from the controller once the Dean approves
 * rather than from WorkflowEngine::decide(). See
 * AppointmentLetterController::issueAppointmentLetter().
 */
class AppointmentLetterMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Raw PDF bytes, base64-encoded. */
    protected string $pdfContents;

    /**
     * Queued jobs are serialized to a JSON envelope around a PHP-serialized
     * payload. Raw binary PDF bytes on a public/protected property break
     * that json_encode() with "Malformed UTF-8 characters" the moment this
     * mailable is queued, so the bytes are base64-encoded going in and
     * decoded again only when building the attachment.
     */
    public function __construct(
        protected Application $application,
        protected AppointmentDetail $detail,
        string $pdfContents,
        protected string $pdfFilename,
    ) {
        $this->pdfContents = base64_encode($pdfContents);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Appointment as Examiner — UTP Centre for Graduate Studies',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'jason::mail.appointment-letter',
            with: [
                'examinerName' => $this->detail->examiner_name,
                'applicationId' => $this->application->id,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => base64_decode($this->pdfContents), $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
    }
}
