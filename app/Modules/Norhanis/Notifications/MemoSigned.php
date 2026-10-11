<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationMemo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the student that the supervisor or the centre head has signed the
 * split budget memo, or has sent it back. $submitted is true when that
 * signature was the last sign-off outstanding.
 */
class MemoSigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Application $application,
        protected PublicationMemo $memo,
        protected string $signer,
        protected bool $submitted = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Publication Application #{$this->application->id}: ".$this->headline())
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->detail())
            ->action('Open your sign-off page', route('publication.sign-offs', $this->application))
            ->line('Thank you for using UResearch 2.0.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => 'Publication',
            'title' => "Publication Application #{$this->application->id}: ".$this->headline(),
            'url' => route('publication.sign-offs', $this->application),
        ];
    }

    protected function headline(): string
    {
        $name = $this->memo->{"{$this->signer}_name"};

        return $this->memo->isDeclined()
            ? "{$name} sent the split budget memo back"
            : "{$name} signed the split budget memo";
    }

    protected function detail(): string
    {
        $name = $this->memo->{"{$this->signer}_name"};

        return match (true) {
            $this->memo->isDeclined() => "{$name} did not sign the split budget memo: \"{$this->memo->decline_reason}\" Correct the grant details on your sign-off page and it will be sent for signature again.",
            $this->submitted => "{$name} has signed the split budget memo. That was the last sign-off outstanding, and your application has been sent to your supervisor's queue.",
            $this->memo->isSigned() => "{$name} has signed the split budget memo, which is now fully signed.",
            default => "{$name} has signed the split budget memo. It has now gone to {$this->memo->head_name} for the second signature.",
        };
    }
}
