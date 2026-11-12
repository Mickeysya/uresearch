<?php

namespace App\Modules\Norhanis\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\PublicationDetail;

/**
 * The "CGS & Grant - Split Memo" follow-up (UAT, 11 Nov 2026).
 *
 * A journal publication funded by CGS and a grant needs a signed memo,
 * which the student sends to CGS outside UResearch. When one reaches final
 * approval the student gets one extra email: why, the memo template, and
 * where to send it.
 *
 * The email itself is sent by Support\PublicationFollowUp, once, with the
 * other cost centres' follow-ups; this holds what the email and the page
 * share: the wording, the contact and the template.
 */
class SplitMemo
{
    /** Shown in the email and on the student's application page. */
    public const INSTRUCTION = 'You can submit the signed memo to CGS at this email.';

    /** True once a Split Memo journal publication is approved: the page shows the instruction. */
    public static function applies(Application $application, ?PublicationDetail $detail): bool
    {
        return $application->status === Application::STATUS_APPROVED
            && (bool) $detail?->usesCostCentre(PublicationDetail::SPLIT_MEMO);
    }

    public static function contact(): string
    {
        return (string) config('norhanis.cgs.split_memo.contact');
    }

    /** The memo template to attach: the first configured file that exists, or null. */
    public static function template(): ?string
    {
        foreach ((array) config('norhanis.cgs.split_memo.templates', []) as $path) {
            $full = str_starts_with($path, '/') ? $path : storage_path('app/'.$path);

            if (is_file($full) && is_readable($full)) {
                return $full;
            }
        }

        return null;
    }
}
