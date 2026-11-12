<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One re-viva message to a user: a `re-viva:monitor` reminder or alert, or
 * an outcome. $kind says which; the wording is built by the caller.
 *
 * Whatever goes to a student or supervisor names no examiner and no
 * individual examiner category: the callers keep to that, and the tests
 * check it.
 */
class ReVivaUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    /** A copy to CGS: the action button only for the Senior Executive (see forCgs()). */
    protected bool $cgsCopy = false;

    /** @param  array<int, string>  $lines */
    public function __construct(
        public readonly string $kind,
        protected Application $application,
        protected string $subject,
        protected array $lines,
        protected ?string $actionLabel = null,
        protected ?string $actionUrl = null,
    ) {
        $this->afterCommit();
    }

    /**
     * Mark this as a CGS-bound email (ReVivaRecipients::cgs()). The Senior
     * Executive keeps the action; the Non-Executive gets Re-viva Cycles,
     * which he can open read-only; anyone else (the Academic Executive, who
     * has no re-viva pages) gets no link and an "information only" line.
     */
    public function forCgs(): self
    {
        $this->cgsCopy = true;

        return $this;
    }

    /** @return array{0: ?string, 1: ?string} label, url for this recipient */
    protected function actionFor(object $notifiable): array
    {
        if (! $this->cgsCopy || ! $notifiable instanceof User || $notifiable->role === \App\Modules\Core\Support\Role::SENIOR_EXEC_CGS) {
            return [$this->actionLabel, $this->actionUrl];
        }

        return $notifiable->role === \App\Modules\Core\Support\Role::NON_EXEC_CGS
            ? ['Open Re-viva Cycles', route('reviva.cycles.index')]
            : [null, null];
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Dear '.($notifiable instanceof User ? $notifiable->name : 'Sir/Madam').',');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        [$label, $url] = $this->actionFor($notifiable);

        if ($url) {
            $mail->action($label ?? 'Open UResearch', $url);
        } elseif ($this->cgsCopy) {
            $mail->line('For your information: no action is needed from you.');
        }

        return $mail->line('Sent by UResearch 2.0.');
    }

    /** Everything the message says, for the tests' "no examiner names" checks. */
    public function text(): string
    {
        return $this->subject."\n".implode("\n", $this->lines);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => $this->application->module()->label(),
            'reference' => $this->application->reference(),
            'title' => $this->subject,
            'message' => $this->lines[0] ?? '',
            'kind' => $this->kind,
        ];
    }
}
