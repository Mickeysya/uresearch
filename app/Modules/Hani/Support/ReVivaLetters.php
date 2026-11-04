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
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stage 1 of the re-viva, "Send to panel", in one action:
 *
 *   1. one "Correction of Master's/Doctoral Thesis" letter per CURRENT main
 *      examiner (after any declines), rendered with Dompdf
 *   2. stored on the private disk, staff-only (the letters name the examiners)
 *   3. report_sent completed through WorkflowEngine::decide()
 *   4. after the commit, each examiner is emailed their letter and a signed
 *      link to the corrected thesis and the Summary of Corrections; an
 *      examiner whose email is missing or reported not working is skipped,
 *      and the reason is kept on the letter row and shown to the AE
 */
class ReVivaLetters
{
    public const STAGE = 'report_sent';

    /** Where a letter may be resent (resend). */
    public const PANEL_STAGE = 'under_panel_review';

    public function __construct(protected WorkflowEngine $engine) {}

    /**
     * @return array{letters: array<int, ReVivaStaffDocument>, emailed: array<int, string>, skipped: array<int, string>}
     */
    public function send(Application $application, User $actor): array
    {
        $letters = DB::transaction(function () use ($application, $actor) {
            $detail = ReVivaDetail::where('application_id', $application->id)->lockForUpdate()->firstOrFail();
            $application->refresh();

            if ($application->current_stage !== self::STAGE || $application->status !== Application::STATUS_PENDING || $detail->sent_to_panel_at !== null) {
                throw ValidationException::withMessages(['send' => 'The letters for this round have already been sent.']);
            }

            $mains = $detail->currentMains();
            $sentAt = now();

            if ($mains === []) {
                throw ValidationException::withMessages(['send' => 'No released panel with main examiners is linked to this round, so no letters can be generated. Check the panel, or record the examiners on the viva first.']);
            }

            $letters = [];
            foreach ($mains as $seat => $examiner) {
                $letters[] = $this->generate($detail, $application, $seat, $examiner, $sentAt, $actor);
            }

            $detail->update(['sent_to_panel_at' => $sentAt, 'sent_to_panel_by_id' => $actor->id]);

            // No examiner names: the student reads the remarks on their tracking page.
            $this->engine->decide($application, $actor, 'approve', 'Correction letters sent to the '.count($letters).' main examiners.');

            activity('re_viva')->causedBy($actor)->performedOn($application)
                ->withProperties(['action' => 'sent_to_panel', 'letters' => count($letters)])
                ->log('Re-viva correction letters sent');

            return $letters;
        });

        return $this->email($letters, $actor);
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
        return Carbon::parse($sentAt)->addDays((int) config('hani.reviva.link_days', 35))->endOfDay();
    }

    /** When the panel's working days run out. */
    public static function reviewDue(\DateTimeInterface $sentAt): Carbon
    {
        return WorkingDays::add(Carbon::parse($sentAt), (int) config('hani.reviva.review_working_days', 20));
    }

    protected function generate(ReVivaDetail $detail, Application $application, string $seat, Examiner $examiner, Carbon $sentAt, User $actor): ReVivaStaffDocument
    {
        $pdf = self::render($detail, $application, $examiner, $sentAt);
        $path = 'hani/reviva-letters/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($path, $pdf);

        return ReVivaStaffDocument::create([
            're_viva_detail_id' => $detail->id,
            'kind' => ReVivaStaffDocument::LETTER,
            'seat' => $seat,
            'examiner_id' => $examiner->id,
            'path' => $path,
            'original_name' => 'correction-letter-'.Str::slug($examiner->name).'.pdf',
            'created_by_id' => $actor->id,
        ]);
    }

    public static function render(ReVivaDetail $detail, Application $application, Examiner $examiner, Carbon $sentAt): string
    {
        $student = $application->student;
        $viva = $detail->vivaDetail();
        $phd = ($viva?->programme_level ?? VivaChecklist::levelFor($student)) === VivaChecklist::PHD;
        $days = (int) config('hani.reviva.review_working_days', 20);

        $data = [
            'reference' => config('hani.reviva.letter.reference_prefix', 'UTP/PGS/AD/').($student->matric_no ?: $student->id),
            'date' => $sentAt,
            'examiner' => $examiner,
            'examinerPlace' => self::placeOf($examiner),
            'student' => $student,
            'degreeWord' => $phd ? 'Doctoral' : 'Master\'s',
            'degreeName' => $phd ? 'Doctor of Philosophy' : 'Master of Science',
            'programme' => $student->programme ?: '-',
            'thesisTitle' => $viva?->thesis_title ?: ($detail->panel()?->thesis_title ?: '-'),
            'workingDays' => $days,
            'workingDaysWords' => self::words($days),
            'dueDate' => self::reviewDue($sentAt),
            'letter' => (array) config('hani.reviva.letter'),
            'round' => $detail->cycle_number,
        ];

        return Pdf::loadView('hani::re_viva.pdf.correction_letter', $data)
            ->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Serif', 'isRemoteEnabled' => false])
            ->output();
    }

    /** @return array<int, string> the lines under the examiner's name */
    protected static function placeOf(Examiner $examiner): array
    {
        if ($examiner->type === Examiner::TYPE_EXTERNAL) {
            return array_values(array_filter([$examiner->department !== 'External' ? $examiner->department : null, $examiner->institution]));
        }

        return array_values(array_filter([
            $examiner->department ? 'Department of '.$examiner->department : null,
            config('hani.reviva.letter.institution'),
        ]));
    }

    /** "twenty" for 20: the letter spells the number out, as the real one does. */
    protected static function words(int $n): string
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
            $name ? $emailed[] = $name : $skipped[] = $problem.' Their letter was generated; use "Resend letter" once the email is updated.';
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
        if (! ($user->role === Role::SENIOR_EXEC_CGS || ($user->role === Role::ACADEMIC_EXEC && $user->department === $application->student?->department))) {
            return 'Only the Academic Executive of the student\'s department or the Senior Executive can resend a letter.';
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
                ->notify(new ReVivaCorrectionLetter($letter, $send));
            $letter->update(['emailed_at' => $letter->emailed_at ?? now(), 'email_skipped_reason' => null]);

            return [$examiner->name, null];
        } catch (\Throwable $e) {
            report($e);
            $letter->update(['email_skipped_reason' => 'The email could not be sent.']);
            isset($send) ? $send->update(['emailed' => false, 'skipped_reason' => 'The email could not be sent.']) : $log(false, 'The email could not be sent.');

            return [null, "The email to {$examiner->name} could not be sent."];
        }
    }
}
