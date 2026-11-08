<?php

namespace App\Modules\Haziq\Notifications;

use App\Modules\Core\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sent weekly (Console\Commands\RemindIncompleteDocuments) or on demand
 * ("Send Reminder to All", Http\Controllers\DocumentCompletenessController
 * ::remindAll()) while a GRA/GA application is still missing a required
 * document. Never blocks the application's own routing through its
 * approval chain — see Support\DocumentCompleteness's own docblock.
 */
class IncompleteDocumentsReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param Collection<int, string> $missing */
    public function __construct(protected Application $application, protected Collection $missing) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'application_id' => $this->application->id,
            'title' => $this->application->module()->label().' is still missing '
                .$this->missing->count().' '.Str::plural('document', $this->missing->count()).'.',
            'body' => $this->missing->implode(', '),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->application->module()->label();

        $mail = (new MailMessage)
            ->subject("{$label} — documents still missing")
            ->greeting('Hi '.$notifiable->name.',')
            ->line("Your {$label} is still missing the following document(s):");

        foreach ($this->missing as $document) {
            $mail->line("- {$document}");
        }

        return $mail
            ->line('Your application keeps moving through the approval chain either way, but it stays incomplete until these are uploaded.')
            ->action('View My Application', route(str_replace('_', '-', $this->application->module_type).'.mine', $this->application));
    }
}
