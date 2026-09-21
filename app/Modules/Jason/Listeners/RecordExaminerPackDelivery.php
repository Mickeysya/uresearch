<?php

namespace App\Modules\Jason\Listeners;

use App\Modules\Jason\Mail\AppointmentLetterMail;
use App\Modules\Jason\Models\AppointmentExaminer;
use Illuminate\Mail\Events\MessageSent;

/**
 * Records that an examiner's appointment pack actually went out.
 *
 * The controller cannot know this. It hands the mailable to the queue and
 * returns; the send happens later in a worker, where a bad address, a dead
 * SMTP host or a fatal in the job leaves no trace on the nomination. The
 * result was a nomination the Dean had approved that looked complete while
 * an examiner had received nothing.
 *
 * MessageSent fires only once the mail transport has accepted the message,
 * so `pack_sent_at` means delivered-to-the-mail-server, not queued. Anything
 * still null on the Issued Nominations page is a pack CGS can resend.
 */
class RecordExaminerPackDelivery
{
    public function handle(MessageSent $event): void
    {
        $header = $event->sent->getOriginalMessage()
            ->getHeaders()
            ->get(AppointmentLetterMail::EXAMINER_HEADER);

        if (! $header) {
            return;
        }

        AppointmentExaminer::whereKey((int) $header->getBodyAsString())
            ->update(['pack_sent_at' => now()]);
    }
}
