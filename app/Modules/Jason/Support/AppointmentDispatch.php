<?php

namespace App\Modules\Jason\Support;

use App\Modules\Core\Contracts\SuppliesAppointmentDispatch;
use App\Modules\Jason\Models\AppointmentDetail;
use App\Modules\Jason\Models\AppointmentExaminer;

/**
 * When an examiner's appointment pack was sent, answered in the nomination
 * chain's own terms.
 *
 * Acceptance slips come back on paper or by email, so the chain's panel
 * page is where a decline is recorded -- and that is far easier to act on
 * when it shows whether the pack actually went out. This module knows,
 * because the MessageSent listener stamps `pack_sent_at` when the mail
 * transport accepts the message.
 *
 * The keys are THEIRS, not this module's: the nomination's own application
 * id, and the examiner's id in the examiner records. Neither is this
 * module's appointment id or its `pool_examiner_id`, which belongs to its
 * own availability pool. Both are stored when a panel is read -- see the
 * migration that adds them -- so an appointment imported before that, or
 * matched by name where the records held nothing, answers null.
 *
 * The LATEST send wins. One nomination can produce several appointments:
 * a resend, or a letter issued later for a promoted backup. The question
 * being asked is "has this reached them", so the most recent yes is the
 * answer, and null means nothing has been accepted for them at all.
 *
 * Implements the Core contract directly, now that Core has the file. It
 * was named as a string while the interface was only proposed, because
 * naming a missing interface is a fatal error the moment the class loads.
 */
final class AppointmentDispatch implements SuppliesAppointmentDispatch
{
    /** @deprecated Kept while callers move to the interface itself. */
    public const CONTRACT = SuppliesAppointmentDispatch::class;

    /**
     * @param  int  $applicationId  the NOMINATION's applications.id
     * @param  int  $examinerId  examiners.id
     */
    public function packSentAt(int $applicationId, int $examinerId): ?\DateTimeInterface
    {
        $applicationIds = AppointmentDetail::where('nomination_application_id', $applicationId)
            ->pluck('application_id');

        if ($applicationIds->isEmpty()) {
            return null;
        }

        return AppointmentExaminer::whereIn('application_id', $applicationIds)
            ->where('hani_examiner_id', $examinerId)
            ->whereNotNull('pack_sent_at')
            ->orderByDesc('pack_sent_at')
            ->value('pack_sent_at');
    }
}
