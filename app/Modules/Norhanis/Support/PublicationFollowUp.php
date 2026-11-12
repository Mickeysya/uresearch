<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationDetail;
use App\Modules\Norhanis\Notifications\ClaimNextStep;
use App\Modules\Norhanis\Notifications\LetterOfUndertakingNotice;
use App\Modules\Norhanis\Notifications\SplitMemoInstructions;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * The one extra email a journal publication's student gets on final
 * approval, by its cost centre (UAT, 11 Nov 2026), beside Core's own
 * "fully approved" email:
 *
 *   Pay and Claims          ClaimNextStep: submit your claim, with a
 *                           "Start your claim" button (the deep link)
 *   Letter of Undertaking   LetterOfUndertakingNotice: CGS pays directly,
 *                           no claim is needed
 *   CGS & Grant - Split Memo SplitMemoInstructions: the signed memo, its
 *                           template and the CGS contact
 *
 * "Others" and every conference get nothing extra.
 *
 * Sent once: follow_up_emailed_at is claimed in the same UPDATE that
 * decides whether to send, so a repeated call -- or two at once -- finds it
 * set and stops.
 */
class PublicationFollowUp
{
    /** Call after a decision went through; does nothing unless it applies. */
    public function afterFinalApproval(Application $application): void
    {
        if ($application->module_type !== 'publication' || $application->status !== Application::STATUS_APPROVED) {
            return;
        }

        $detail = PublicationDetail::where('application_id', $application->id)->first();

        if (! $detail || ! $notification = $this->notificationFor($application, $detail)) {
            return;
        }

        $claimed = PublicationDetail::whereKey($detail->id)
            ->whereNull('follow_up_emailed_at')
            ->update(['follow_up_emailed_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        // Best effort, like every email from this module: the approval is
        // the durable thing, and a mail outage must not undo it.
        try {
            $application->student?->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Publication follow-up email failed', ['application_id' => $application->id, 'error' => $e->getMessage()]);
        }
    }

    protected function notificationFor(Application $application, PublicationDetail $detail): ?Notification
    {
        return match (true) {
            $detail->usesCostCentre(PublicationDetail::PAY_AND_CLAIMS) => new ClaimNextStep($application),
            $detail->usesCostCentre(PublicationDetail::LETTER_OF_UNDERTAKING) => new LetterOfUndertakingNotice($application),
            $detail->usesCostCentre(PublicationDetail::SPLIT_MEMO) => new SplitMemoInstructions($application),
            default => null,
        };
    }
}
