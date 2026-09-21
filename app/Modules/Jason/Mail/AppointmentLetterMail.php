<?php

namespace App\Modules\Jason\Mail;

use App\Modules\Core\Models\Application;
use App\Modules\Jason\Models\AppointmentExaminer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers an examiner their appointment pack: the appointment letter and
 * the thesis evaluation report form they will return.
 *
 * Every other decision email in this portal is an
 * `App\Modules\Core\Notifications\ApplicationDecided` sent to a `User`
 * Eloquent model, because every other recipient has a login. An examiner
 * never does -- their only record in the system is an appointment_examiners
 * row -- so this has to be a plain Mailable addressed by a bare email
 * string, dispatched from the controller once the Dean approves rather than
 * from WorkflowEngine::decide().
 */
class AppointmentLetterMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Queued jobs are serialized to a JSON envelope around a PHP-serialized
     * payload. Raw binary PDF bytes on a public/protected property break
     * that json_encode() with "Malformed UTF-8 characters" the moment this
     * mailable is queued, so each file's bytes are base64-encoded going in
     * and decoded again only when building the attachment.
     *
     * @var array<int, array{name: string, contents: string}>
     */
    protected array $files = [];

    /**
     * @param  array<int, array{name: string, contents: string}>  $files  raw PDF bytes keyed by filename
     */
    public function __construct(
        protected Application $application,
        protected AppointmentExaminer $examiner,
        array $files,
    ) {
        foreach ($files as $file) {
            $this->files[] = ['name' => $file['name'], 'contents' => base64_encode($file['contents'])];
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Appointment as '.$this->examiner->typeLabel().', UTP Centre for Graduate Studies',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'jason::mail.appointment-letter',
            with: [
                'examinerName' => $this->examiner->examiner_name,
                'examinerRole' => $this->examiner->typeLabel(),
                'applicationId' => $this->application->id,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromData(fn () => base64_decode($file['contents']), $file['name'])
                ->withMime('application/pdf'),
            $this->files,
        );
    }
}
