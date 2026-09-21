<?php

namespace App\Modules\Jason\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Stage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the candidate their hardbound submission was rejected, and that this
 * particular application is now closed.
 *
 * The engine's own ApplicationDecided already fires, but it says only "was
 * not approved at the Supervisor stage" and "contact your supervisor" --
 * true of every module, and wrong here: this chain has two ways forward.
 * This one names who rejected it, quotes what they asked for, and says what
 * the candidate can do about it.
 */
class HardboundSubmissionRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected Stage $stage,
        protected string $rejectedBy,
        protected ?string $remarks,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        $title = "Hardbound submission #{$this->application->id} was rejected by the {$this->stage->label}"
            .($this->rejectedBy ? " ({$this->rejectedBy})" : '').'. ';

        $title .= $this->remarks
            ? "Reason: “{$this->remarks}” This application is now closed — correct it and resubmit, or appeal the decision."
            : 'This application is now closed — correct it and resubmit, or appeal the decision.';

        return [
            'application_id' => $this->application->id,
            'module' => 'Hardbound Submission',
            // Renders the feed entry in the "not approved" colour.
            'approved' => false,
            'stage_label' => $this->stage->label,
            'title' => $title,
            'next_stage' => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Hardbound submission #{$this->application->id} rejected — UTP Centre for Graduate Studies")
            ->greeting("Dear {$notifiable->name},")
            ->line("Your hardbound thesis submission #{$this->application->id} was rejected by the {$this->stage->label}"
                .($this->rejectedBy ? " ({$this->rejectedBy})" : '').'.');

        if ($this->remarks) {
            $mail->line('**What needs to be corrected:**')->line("“{$this->remarks}”");
        }

        return $mail
            ->line('**This application is now closed.** Nothing further happens on it, and the decision stays on your record.')
            ->line('You have two ways forward, and you may take one of them:')
            ->line('1. **Correct and resubmit** — this opens a new submission that starts again from your supervisor.')
            ->line('2. **Appeal the decision** — if you believe the rejection was mistaken, file an appeal with a memo and your grounds.')
            ->action('Open Hardbound Submission', route('hardbound.create'))
            ->line('Both options are on that page.')
            ->salutation('Centre for Graduate Studies, Universiti Teknologi PETRONAS');
    }
}
