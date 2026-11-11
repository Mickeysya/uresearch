<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaLetterSend;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Notifications\ReVivaCorrectionLetter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Send to panel", after the Dean approves: makes sure each main examiner has
 * a portal account, starts the working-day clock, and emails each examiner
 * their pack (ReVivaCorrectionLetter). An examiner whose email is missing or
 * reported not working is skipped, and the reason is shown to the Senior
 * Executive.
 */
class ReVivaLetters
{
    public const STAGE = 'ready_to_send';

    /** Where a pack may be resent (resend). */
    public const PANEL_STAGE = 'under_panel_review';

    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * @return array{letters: array<int, ReVivaStaffDocument>, emailed: array<int, string>, skipped: array<int, string>}
     */
    public function send(Application $application, User $actor): array
    {
        $letters = DB::transaction(function () use ($application) {
            $detail = ReVivaDetail::where('application_id', $application->id)->lockForUpdate()->firstOrFail();
            $application->refresh();

            if ($application->current_stage !== self::STAGE || $application->status !== Application::STATUS_PENDING || $detail->sent_to_panel_at !== null) {
                throw ValidationException::withMessages(['send' => 'The letters for this round have already been sent.']);
            }

            $letters = $detail->staffDocuments()->where('kind', ReVivaStaffDocument::LETTER)->with('examiner')->get();

            if ($letters->isEmpty()) {
                throw ValidationException::withMessages(['send' => 'No letters have been prepared for this round.']);
            }

            foreach ($letters as $letter) {
                ReVivaExaminerAccounts::ensure($letter->examiner);
            }

            return $letters;
        });

        // Sent now, not queued, so the page can say who got it. Only the ones
        // not yet sent: pressing Send again after a failure does not double up.
        $result = $this->email($letters->whereNull('emailed_at')->all(), $actor);
        $result['letters'] = $letters->all();

        if ($letters->every(fn (ReVivaStaffDocument $l) => $l->fresh()->emailed_at !== null)) {
            DB::transaction(function () use ($application, $actor, $letters) {
                $sentAt = now();
                ReVivaDetail::where('application_id', $application->id)->update(['sent_to_panel_at' => $sentAt, 'sent_to_panel_by_id' => $actor->id, 'panel_deadline' => self::reviewDue($sentAt)]);
                // No examiner names: the student reads the remarks on their tracking page.
                $this->engine->decide($application->refresh(), $actor, 'approve', 'Sent to the '.$letters->count().' main examiners.');

                activity('re_viva')->causedBy($actor)->performedOn($application)
                    ->withProperties(['action' => 'sent_to_panel', 'letters' => $letters->count()])
                    ->log('Re-viva pack sent to the panel');
            });

            $result['moved'] = true;
            app(ReVivaProcess::class)->tell([$application->student], new \App\Modules\Hani\Notifications\ReVivaUpdate('sent_to_examiners', $application,
                'Re-viva: sent to the examiners', ['Your re-corrected thesis has been sent to the examiners for re-evaluation. You will be told the result here and by email.'],
                'Open Re-viva', route('reviva.mine')));
        } else {
            $result['moved'] = false;
        }

        return $result;
    }

    /** The signed, expiring link an examiner opens without logging in. */
    public static function panelLink(ReVivaDetail $detail, Examiner $examiner, ?Carbon $expires = null): string
    {
        return URL::temporarySignedRoute('reviva.panel.documents', $expires ?? self::panelLinkExpiry($detail), [
            'detail' => $detail->id,
            'examiner' => $examiner->id,
        ]);
    }

    /**
     * A link works for link_days from when it is made, which is when the
     * email is built in the queue worker, on the real clock. The recorded
     * send date is only the earliest start. The demo seeder records sends
     * weeks in the past; a link counted from that date arrived already
     * expired, and Laravel reported it as "Invalid signature".
     */
    public static function panelLinkExpiry(ReVivaDetail $detail): Carbon
    {
        return self::linkExpiry(now()->max($detail->sent_to_panel_at ?? now()));
    }

    public static function linkExpiry(\DateTimeInterface $sentAt): Carbon
    {
        return Carbon::parse($sentAt)->addDays((int) config('hani.reviva.signed_link_days', 35))->endOfDay();
    }

    /** When the panel's working days run out. */
    public static function reviewDue(\DateTimeInterface $sentAt): Carbon
    {
        return WorkingDays::add(Carbon::parse($sentAt), (int) config('hani.reviva.panel_working_days', 20));
    }

    /** "twenty" for 20: the letter spells the number out, as the real one does. */
    public static function words(int $n): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
            'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty'];

        if ($n < 20) {
            return $ones[max(0, $n)];
        }

        return isset($tens[intdiv($n, 10)]) ? $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '') : (string) $n;
    }

    /**
     * Email each letter, after the commit. A mail failure never undoes the
     * stage: the AE sees who was not reached and can use "Resend letter".
     *
     * @param  array<int, ReVivaStaffDocument>  $letters
     */
    protected function email(array $letters, User $actor): array
    {
        $emailed = [];
        $skipped = [];

        foreach ($letters as $letter) {
            [$name, $problem] = $this->deliver($letter, $actor, ReVivaLetterSend::FIRST);
            $name ? $emailed[] = $name : $skipped[] = $problem;
        }

        return ['letters' => $letters, 'emailed' => $emailed, 'skipped' => $skipped];
    }

    /**
     * "Resend letter": the same stored letter to the same examiner, in a new
     * email whose link works for link_days from now (panelLinkExpiry). Only
     * while the round is Under Panel Review, and only to an examiner who
     * still holds the seat. A flagged email is refused with the usual reason.
     *
     * @return array{emailed: ?string, skipped: ?string}
     */
    public function resend(ReVivaStaffDocument $letter, User $actor): array
    {
        $letter = DB::transaction(function () use ($letter, $actor) {
            ReVivaDetail::whereKey($letter->re_viva_detail_id)->lockForUpdate()->firstOrFail();
            $letter = $letter->fresh(['detail.application.student', 'examiner']);

            if ($reason = self::resendBlock($letter, $actor)) {
                throw ValidationException::withMessages(['resend' => $reason]);
            }

            return $letter;
        });

        [$name, $problem] = $this->deliver($letter, $actor, ReVivaLetterSend::RESEND);

        activity('re_viva')->causedBy($actor)->performedOn($letter->detail->application)
            ->withProperties(['action' => 'letter_resent', 'letter_id' => $letter->id, 'examiner_id' => $letter->examiner_id, 'emailed' => $name !== null])
            ->log($name ? 'Re-viva correction letter resent' : 'Re-viva correction letter resend refused');

        return ['emailed' => $name, 'skipped' => $problem ? $problem.' The letter was not resent; update the examiner\'s email first.' : null];
    }

    /**
     * Why $user may not resend $letter now, or null if they may: the AE of the
     * student's department or the Senior Executive, a letter to a current
     * main examiner, a round Under Panel Review.
     */
    public static function resendBlock(ReVivaStaffDocument $letter, User $user): ?string
    {
        $application = $letter->detail?->application;

        if ($letter->kind !== ReVivaStaffDocument::LETTER || ! $application) {
            return 'Only a correction letter can be resent.';
        }
        if ($user->role !== Role::SENIOR_EXEC_CGS) {
            return 'Only the Senior Executive can resend a letter.';
        }
        if ($application->status !== Application::STATUS_PENDING || $application->current_stage !== self::PANEL_STAGE) {
            return 'Letters can be resent only while the round is Under Panel Review.';
        }
        if (! collect($letter->detail->currentMains())->contains(fn (Examiner $e) => $e->id === $letter->examiner_id)) {
            return ($letter->examiner?->name ?? 'This examiner').' no longer holds a main seat on the panel, so the letter cannot be resent.';
        }

        return null;
    }

    /**
     * Email one letter and log it, sent or refused.
     *
     * @return array{0: ?string, 1: ?string} the examiner's name when emailed, else the reason
     */
    protected function deliver(ReVivaStaffDocument $letter, User $actor, string $kind): array
    {
        $examiner = $letter->examiner;
        $log = fn (bool $emailed, ?string $reason) => ReVivaLetterSend::create([
            're_viva_staff_document_id' => $letter->id,
            'sent_by_id' => $actor->id,
            'kind' => $kind,
            'emailed' => $emailed,
            'skipped_reason' => $reason,
            // link_expires_at: written by the email when it is built.
        ]);

        if (! $examiner || $examiner->emailNeedsUpdating()) {
            $reason = $examiner?->emailProblem() ?? 'The examiner is no longer on record.';
            $letter->update(['email_skipped_reason' => $reason]);
            $log(false, $reason);

            return [null, $reason];
        }

        try {
            $send = $log(true, null);
            Notification::route('mail', [$examiner->usableEmail() => $examiner->name])
                ->notifyNow(new ReVivaCorrectionLetter($letter, $send));
            $letter->update(['emailed_at' => $letter->emailed_at ?? now(), 'email_skipped_reason' => null]);

            return [$examiner->name, null];
        } catch (\Throwable $e) {
            report($e);
            $reason = 'The email could not be sent: '.Str::limit($e->getMessage(), 160);
            $letter->update(['email_skipped_reason' => $reason]);
            isset($send) ? $send->update(['emailed' => false, 'skipped_reason' => $reason]) : $log(false, $reason);

            return [null, "The email to {$examiner->name} could not be sent ({$e->getMessage()})."];
        }
    }
}
