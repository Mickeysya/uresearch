<?php

namespace App\Modules\Norhanis\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Support\SplitMemo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * To the student, once, when a journal publication on the "CGS & Grant -
 * Split Memo" cost centre is finally approved: the signed memo they must
 * send CGS, its template attached, and where to send it. Sent by
 * Support\SplitMemo, beside Core's own "fully approved" email.
 *
 * The template is looked up when the mail is built, on the queue worker,
 * so a file added after the approval is still sent. Missing, the email
 * goes without it and a warning is logged for whoever runs the server.
 */
class SplitMemoInstructions extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = SplitMemo::contact();

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your Publication application has been approved.')
            ->line('Because this application is funded by CGS and a grant (CGS & Grant - Split Memo), you must submit a signed memo to CGS.')
            ->line($this->template() ? 'The memo template is attached to this email.' : 'Please ask CGS for the memo template at the email below.')
            ->line(SplitMemo::INSTRUCTION.' '.$contact)
            ->action('View your application', route('applications.show', $this->application))
            ->line('Thank you for using UResearch 2.0.');

        if ($template = $this->template()) {
            $mail->attach($template, ['as' => 'split-memo-template.'.pathinfo($template, PATHINFO_EXTENSION)]);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => 'Publication',
            'title' => $this->subject(),
            'url' => route('applications.show', $this->application),
        ];
    }

    protected function subject(): string
    {
        return "Publication Application #{$this->application->id}: Submit the signed Split Memo to CGS";
    }

    /** The template's path, or null (with a warning) when none is on disk. */
    protected function template(): ?string
    {
        if ($this->templateChecked) {
            return $this->templatePath;
        }

        $this->templateChecked = true;
        $this->templatePath = SplitMemo::template();

        if ($this->templatePath === null) {
            Log::warning('Split Memo template not found; email sent without it.', [
                'application_id' => $this->application->id,
                'looked_for' => config('norhanis.cgs.split_memo.templates'),
            ]);
        }

        return $this->templatePath;
    }

    protected bool $templateChecked = false;

    protected ?string $templatePath = null;
}
