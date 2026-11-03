<?php

namespace App\Modules\Chloe\Notifications;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Services\CandidacyAppealService;
use App\Modules\Chloe\Support\CandidacyAppealPresenter;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The candidacy expiry reminder: e-mail (plain English, through the normal mail setup) plus a bell notification that carries a link.
 *   A  normal: Softbound or an extension appeal
 *   B  no further appeal possible: Softbound is the only option
 *   C  an appeal was returned to the student and is not yet resubmitted
 * A demo student has no login: e-mail only.
 */
class CandidacyExpiryReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $variant,
        public readonly int $months,
        public readonly string $name,
        public readonly CarbonInterface $expiry,
        public readonly int $remaining,
        public readonly ?string $blockedReason = null,
        public readonly ?CandidacyAppeal $appeal = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function date(): string
    {
        return $this->expiry->format('j M Y');
    }

    public function subject(): string
    {
        return $this->variant === 'C'
            ? "Action needed: your extension appeal was returned (candidacy expires {$this->date()})"
            : "Study Candidacy expiry reminder: {$this->months} month(s) left (expires {$this->date()})";
    }

    public function link(): string
    {
        return $this->variant === 'C' && $this->appeal ? route('candidacy.appeals.show', $this->appeal) : route('candidacy.status');
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->subject(), 'module' => 'Study Candidacy', 'approved' => $this->variant !== 'C', 'url' => $this->link(), 'variant' => $this->variant, 'months' => $this->months];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->date();
        $mail = (new MailMessage)->subject($this->subject())->greeting("Dear {$this->name},")->salutation("Best regards,\nCentre for Graduate Studies (CGS)\nUniversiti Teknologi PETRONAS");

        if ($this->variant === 'C') {
            $returned = $this->appeal?->events()->where('action', 'returned')->get()->last();
            $stage = $returned && $returned->stage ? app(CandidacyAppealService::class)->stageLabel($returned->stage) : 'a step';
            $reasons = $returned ? CandidacyAppealPresenter::reasonLabels($returned->stage, 'returned', $returned->reason_codes) : '';

            return $mail
                ->line("Your extension appeal {$this->appeal?->appeal_no} was returned to you at the {$stage} step.")
                ->line('Reasons: '.($reasons !== '' ? $reasons : 'see the appeal').'.'.($returned?->remark ? ' Remark: '.rtrim($returned->remark, '.').'.' : ''))
                ->line("Please revise it and resubmit it on or before {$date}, the day your candidacy period ends.")
                ->action('Open appeal', $this->link())
                ->line('Contact the Centre for Graduate Studies (CGS) if you need help.');
        }

        $mail->line("Your candidacy period ends on {$date} ({$this->months} month(s) from now). Before that date you can:")
            ->line('1) Submit your soft-bound thesis (the candidacy period ends at Softbound submission).');

        $mail->line($this->variant === 'B'
            ? "2) No further extension appeal can be submitted for this candidacy ({$this->blockedReason}). Please submit your soft-bound thesis on or before {$date}."
            : "2) Apply for an extension. The appeal must be submitted on or before {$date}; you have {$this->remaining} of 12 months left.");

        return $mail->action('Open Study Candidacy', $this->link())
            ->line('If you have already done this, please ignore this message.')
            ->line('Students who exceed the maximum candidacy period without submitting may be dismissed under the Postgraduate Student Handbook (section 4.3). Contact CGS if you need help.');
    }
}
