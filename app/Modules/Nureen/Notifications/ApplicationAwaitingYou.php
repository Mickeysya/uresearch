<?php

namespace App\Modules\Nureen\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A new application is waiting for you", to whoever acts FIRST on a newly
 * submitted GA Extension, Certification, Supervision request or Attendance
 * Appeal. Core's engine only notifies a stage's owners when an EARLIER
 * stage decides, so without this the first reviewer was never told --
 * see Support\SubmissionNotifier.
 *
 * Mail only: the in-app feed sends staff back to the feed rather than to an
 * application (applications.show is students-only), so the queue link here
 * is the useful one.
 */
class ApplicationAwaitingYou extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected string $moduleLabel,
        protected Stage $stage,
        protected string $queueUrl,
        protected ?CarbonInterface $dueDate = null,
        protected ?int $charterDays = null,
        protected ?string $reassignedReason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $student = $this->application->student;
        $who = $student ? $student->name.($student->matric_no ? " ({$student->matric_no})" : '') : 'A student';
        $action = match ($this->stage->decision) {
            'endorsed' => 'your endorsement',
            'approved' => 'your decision',
            'reviewed', 'verified' => 'your review',
            default => 'your action',
        };

        $mail = (new MailMessage)
            ->subject("{$this->moduleLabel} {$this->application->reference()} is waiting for {$action}")
            ->greeting('Hi '.$notifiable->name.',');

        $mail = $this->reassignedReason
            ? $mail->line("CGS has passed {$this->moduleLabel} {$this->application->reference()} from {$who} to you, at the {$this->stage->label} stage.")
                ->line('Reason given: '.$this->reassignedReason)
            : $mail->line("{$who} has submitted a {$this->moduleLabel} request ({$this->application->reference()}). It is now waiting for {$action} at the {$this->stage->label} stage.");

        if ($this->dueDate) {
            $mail = $mail->line('Client Charter: please decide by '.$this->dueDate->format('l, j F Y')
                .($this->charterDays !== null ? " ({$this->charterDays} working ".($this->charterDays === 1 ? 'day' : 'days').')' : '').'.');
        }

        return $mail
            ->action('Open your queue', $this->queueUrl)
            ->line('Thank you for using UResearch 2.0.');
    }
}
