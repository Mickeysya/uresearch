<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Haziq\Models\GaApplicationDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The student-facing counterpart to GaInterviewFailed -- confirmed
 * explicitly 2026-11-10: a Pass used to only notify the next approver
 * (notifyNextApprover() in GaApplicationController::decide()), leaving
 * the student themselves to find out only by checking the page. A Fail
 * already told the student outright (ApplicationRejected); a Pass
 * deserves the same courtesy, worded for an outcome that is good news
 * and still has a stage left to go, not a final decision.
 *
 * Refined the same day: the email used to carry neither Research
 * Centre's own notes nor the Interview Report file, and read as flat,
 * routine copy for what is actually good news for the student --
 * confirmed explicitly: "buat lah congratulation notes cantik sikit.
 * bagi nampak happy vibe student pass interview. student pun boleh
 * download interview result tu." The report is attached directly
 * (same as LetterIssued does for the offer letters), not just linked --
 * it is also already listed under the application's own Documents
 * section in-app, so this is a convenience, not the only way to get it.
 */
class GaInterviewPassed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application, protected GaApplicationDetail $detail) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => 'Congratulations, you passed your GA interview!',
            'body' => 'Your Research Centre interview result is Pass. Your GA application has moved on to CGS for review.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Congratulations, you passed your GA interview! Application #{$this->application->id}")
            ->greeting('Congratulations, '.$notifiable->name.'!')
            ->line('Great news: you passed your Research Centre interview.')
            ->line('Your GA application has now moved on to CGS for review.');

        if ($this->detail->interview_notes) {
            $mail->line('A note from Research Centre: "'.$this->detail->interview_notes.'"');
        }

        $report = $this->interviewReport();

        if ($report) {
            $mail->line('Your interview result report is attached to this email, and also available any time from your application page.');
            $mail->attach(
                Attachment::fromStorageDisk('local', $report->path)
                    ->as($report->original_name)
                    ->withMime($report->mime_type ?? 'application/pdf'),
            );
        }

        return $mail->action('View my application', route('ga-application.mine', $this->application));
    }

    protected function interviewReport(): ?ApplicationDocument
    {
        $report = ApplicationDocument::where('application_id', $this->application->id)
            ->where('doc_type', 'Interview Report')
            ->latest('id')
            ->first();

        return $report?->exists() ? $report : null;
    }
}
