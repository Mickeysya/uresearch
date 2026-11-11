<?php

namespace App\Modules\Hani\Notifications;

use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\ReVivaLetterSend;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Support\ReVivaExaminerAccounts;
use App\Modules\Hani\Support\ReVivaLetters;
use App\Modules\Hani\Support\ReVivaProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The re-examination pack to one main examiner: their letter and report form
 * always; the student's three documents attached, or behind the signed link
 * when the total is over hani.reviva.attachment_limit_mb. Also a calendar
 * event on the deadline, and the My Re-evaluations sign-in.
 */
class ReVivaCorrectionLetter extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReVivaStaffDocument $letter, public ?ReVivaLetterSend $send = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $detail = $this->letter->detail;
        $student = $detail->application->student;
        $examiner = $this->letter->examiner;
        $sentAt = $detail->sent_to_panel_at ?? now();
        // Counted from now, when the email is built, not from $sentAt.
        $expires = ReVivaLetters::panelLinkExpiry($detail);
        $resent = $this->send?->kind === ReVivaLetterSend::RESEND;
        $this->send?->update(['link_expires_at' => $expires]);

        $disk = Storage::disk('local');
        $form = $detail->staffDocuments()->where('kind', ReVivaStaffDocument::REPORT_FORM)->where('examiner_id', $examiner->id)->first();
        $studentDocs = $detail->studentDocuments();
        $mine = array_filter([$this->letter, $form]);
        $total = collect($mine)->sum(fn ($d) => $disk->size($d->path)) + $studentDocs->sum(fn ($d) => $disk->size($d->path));
        $attachAll = $total <= (int) config('hani.reviva.attachment_limit_mb', 20) * 1048576;
        $due = $detail->panel_deadline ?? ReVivaLetters::reviewDue($sentAt);
        $account = ReVivaExaminerAccounts::find($examiner);

        $data = [
            'examinerName' => $examiner->name,
            'studentName' => $student->name,
            'resent' => $resent,
            'panelLink' => $attachAll ? null : ReVivaLetters::panelLink($detail, $examiner, $expires),
            'expires' => $expires->format('j F Y'),
            'due' => $due->format('j F Y'),
            'myUrl' => route('reviva.examiner.index'),
            'setupUrl' => $account && $account->role === Role::FACULTY && $account->email_verified_at === null
                ? ReVivaExaminerAccounts::setupLink($account) : null,
        ];

        // Its own Markdown template, because MailMessage holds only one
        // button. Rendered as HTML (inline links) and as plain text (each
        // URL on its own line), with the same mail theme as other emails.
        $markdown = app(Markdown::class);
        $view = 'hani::re_viva.emails.correction_letter';
        $mail = (new MailMessage)
            ->subject(($resent ? 'Resent: ' : '').'Correction of thesis for re-evaluation: '.$student->name)
            ->view([
                'html' => fn () => $markdown->render($view, $data + ['plain' => false]),
                'text' => fn () => $markdown->renderText($view, $data + ['plain' => true]),
            ], $data);

        foreach ($mine as $document) {
            $mail->attachData($disk->get($document->path), $document->original_name, ['mime' => 'application/pdf']);
        }
        if ($attachAll) {
            foreach ($studentDocs as $document) {
                $mail->attachData($disk->get($document->path), $document->original_name, ['mime' => 'application/pdf']);
            }
        }

        return $mail->attachData(ReVivaProcess::ics('reviva-deadline-'.$detail->id.'-'.$examiner->id, 'Re-evaluation report due: '.$student->name, $due, null), 'deadline.ics', ['mime' => 'text/calendar; charset=utf-8']);
    }
}
