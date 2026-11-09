<?php

namespace App\Modules\Chloe\Listeners;

use App\Modules\Chloe\Support\MailArchive;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registered in ModuleProvider for Illuminate\Mail\Events\MessageSent: it fires after the transport has accepted a
 * message, in whichever process sent it (web request, queue worker, scheduler). A failure to archive is logged and
 * never stops or changes the e-mail itself.
 */
class ArchiveSentMail
{
    public function handle(MessageSent $event): void
    {
        if (! MailArchive::enabled()) {
            return;
        }

        try {
            MailArchive::record($event);
        } catch (Throwable $e) {
            Log::error('Mail archive failed: '.$e->getMessage());
        }
    }
}
