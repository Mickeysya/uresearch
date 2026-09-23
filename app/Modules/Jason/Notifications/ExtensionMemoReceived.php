<?php

namespace App\Modules\Jason\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the candidate CGS has their extension memo.
 *
 * The engine's own ApplicationDecided fires too, and on the last stage of a
 * chain it says "All approvals are complete. Your application has been fully
 * approved." That is exactly the wrong thing to tell someone here: CGS
 * receiving the memo is not the Dean granting the extension. This one says
 * what actually happened and what happens next.
 */
class ExtensionMemoReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'module' => 'Appeal Hardbound Submission',
            'approved' => true,
            'stage_label' => 'Non-Executive CGS',
            'title' => "Memo received for appeal #{$this->application->id}. "
                .'The Centre for Graduate Studies has your appeal for an extension of your hardbound '
                .'thesis submission and it is now under consideration. Please wait for further notification.',
            'next_stage' => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Memo received: appeal #{$this->application->id} for an extension of hardbound thesis submission")
            ->greeting("Dear {$notifiable->name},")
            ->line('**Memo received.** The Centre for Graduate Studies has your appeal for an extension '
                .'of your hardbound thesis submission, endorsed by your supervisor and your HOD/Chair.')
            ->line('It now goes to the Dean of Postgraduate and Research for a decision. '
                .'**Please wait for further notification** — CGS will contact you directly with the outcome.')
            ->action('Track this appeal', route('applications.index'))
            ->line('You do not need to do anything in the meantime.')
            ->salutation('Centre for Graduate Studies, Universiti Teknologi PETRONAS');
    }
}
