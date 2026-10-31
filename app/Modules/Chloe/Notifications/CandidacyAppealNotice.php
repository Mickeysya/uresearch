<?php

namespace App\Modules\Chloe\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One class for the whole appeal notification matrix: the wording of each case is config('candidacy.mail.<template>')
 * and the placeholders are filled from $vars. E-mail and bell always go together; a demo student (no login) gets
 * the e-mail only, because an anonymous notifiable has no 'database' channel. The subject always starts with the appeal number.
 */
class CandidacyAppealNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $vars
     */
    public function __construct(
        public readonly string $template,
        public readonly array $vars,
        public readonly bool $approved = true,
        public readonly ?string $nextStage = null,
        public readonly ?string $memoPath = null,
        public readonly ?string $memoName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->fill((string) config("candidacy.mail.{$this->template}.title")),
            'module' => 'Study Candidacy Appeal',
            'approved' => $this->approved,
            'next_stage' => $this->nextStage,
            'appeal_no' => $this->vars['appeal_no'] ?? null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $config = config("candidacy.mail.{$this->template}");
        $mail = (new MailMessage)->subject($this->fill($config['subject']));

        foreach ($config['lines'] as $line) {
            $mail->line($this->fill($line));
        }

        if ($this->memoPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($this->memoPath)) {
            $mail->attach(Attachment::fromStorageDisk('local', $this->memoPath)->as($this->memoName ?: 'memo.pdf')->withMime('application/pdf'));
        }

        return $mail;
    }

    public function fill(string $text): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => (string) ($this->vars[$m[1]] ?? ''), $text);
    }
}
