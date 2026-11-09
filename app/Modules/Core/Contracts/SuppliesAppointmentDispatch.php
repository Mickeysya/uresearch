<?php

namespace App\Modules\Core\Contracts;

/**
 * When an examiner's appointment pack was sent, for the module that owns the
 * panel (Hani) to show next to each seat. Implemented by the module that
 * sends the letters (Jason: appointment_examiners.pack_sent_at).
 */
interface SuppliesAppointmentDispatch
{
    /**
     * Keyed by the NOMINATION's applications.id and the examiner's
     * examiners.id. The latest non-null pack_sent_at for that pair; null
     * when nothing has been sent.
     */
    public function packSentAt(int $applicationId, int $examinerId): ?\DateTimeInterface;
}
