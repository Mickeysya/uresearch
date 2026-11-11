<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaDocumentReturn;
use App\Modules\Hani\Models\ReVivaEvaluation;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Notifications\ReVivaMeetingInvite;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Every step of the re-examination after the student submits. Status and
 * stage only ever change through WorkflowEngine. Emails go after the commit
 * and a mail failure never undoes a step.
 */
class ReVivaProcess
{
    public const CONSOLIDATION = 'consolidation';

    public const RE_VIVA = 're_viva';

    public function __construct(
        protected WorkflowEngine $engine,
        protected DocumentStore $documents,
        protected ReVivaDocuments $pack,
        protected ReVivaOutcome $outcomes,
    ) {}

    public static function examinesOpenRound(User $user): bool
    {
        $ids = ReVivaExaminerAccounts::examinerIds($user);

        return $ids !== [] && ReVivaStaffDocument::where('kind', ReVivaStaffDocument::LETTER)->whereIn('examiner_id', $ids)
            ->whereHas('detail', fn ($d) => $d->whereNotNull('sent_to_panel_at')->whereNull('outcome_level'))->exists();
    }

    public function submitted(ReVivaDetail $detail): void
    {
        $student = $detail->application->student;
        $this->tell([$student], new ReVivaUpdate('student_submitted', $detail->application, 'Re-viva documents submitted',
            ["Your documents for re-viva round {$detail->cycle_number} have been submitted. CGS will check them and let you know."],
            'Open Re-viva', route('reviva.mine')));
        $this->tell($this->seniorExecs(), new ReVivaUpdate('submitted', $detail->application, "Re-viva documents submitted: {$student->name}",
            ["{$student->name} has submitted the documents for re-viva round {$detail->cycle_number}. Please check them within ".config('hani.reviva.doc_check_sla_days').' working days.'],
            'Open Document Check', route('reviva.queue', ['stage' => 'student_submitted'])));
    }

    /** @param array<int, string> $types */
    public function returnToStudent(Application $application, User $actor, array $types, string $reason): void
    {
        $detail = $this->at($application, 'student_submitted');

        DB::transaction(function () use ($application, $actor, $types, $reason, $detail) {
            ReVivaDocumentReturn::create(['re_viva_detail_id' => $detail->id, 'documents' => array_values($types), 'reason' => $reason, 'returned_by_id' => $actor->id]);
            $this->engine->decide($application, $actor, 'return', $reason);
        });

        $this->tell([$application->student], new ReVivaUpdate('returned', $application, 'Re-viva documents returned',
            ['CGS has returned these documents: '.implode(', ', $types).'.', 'Reason: '.$reason],
            'Open Re-viva', route('reviva.mine')));
    }

    /** @param array<string, UploadedFile> $files */
    public function resubmit(ReVivaDetail $detail, array $files): void
    {
        $application = $detail->application;
        $return = $detail->documentReturns()->first();

        if ($application->status !== Application::STATUS_RETURNED || ! $return) {
            throw ValidationException::withMessages(['documents' => 'These documents have not been returned.']);
        }

        DB::transaction(function () use ($application, $files, $return) {
            foreach ($files as $type => $file) {
                $this->documents->attach($application, $file, $type);
            }
            $return->update(['resubmitted_at' => now()]);
            $this->engine->resubmit($application);
        });

        $this->submitted($detail);
    }

    public function accept(Application $application, User $actor): void
    {
        $detail = $this->at($application, 'student_submitted');

        if ($detail->currentMains() === []) {
            throw ValidationException::withMessages(['pack' => 'No released panel with main examiners is linked to this round, so the letters cannot be prepared.']);
        }

        DB::transaction(function () use ($application, $actor, $detail) {
            $detail->update(['documents_accepted_at' => now()]);
            $this->pack->generate($detail, $actor);
            $this->engine->decide($application, $actor, 'approve', 'Documents accepted.');
        });

        $this->tell([$application->student], new ReVivaUpdate('accepted', $application, 'Re-viva documents accepted',
            ['Your re-corrected thesis, Summary of Corrections and Confirmation of Correction have been received and accepted. CGS will now prepare the re-examination.'],
            'Open Re-viva', route('reviva.mine')));
    }

    public function regenerate(Application $application, User $actor): void
    {
        $this->pack->generate($this->at($application, 'pack_preparation'), $actor);
    }

    public function sendToDean(Application $application, User $actor): void
    {
        $this->at($application, 'pack_preparation');
        $this->engine->decide($application, $actor, 'approve', 'Sent to the Dean of PGR.');

        $name = $application->student->name;
        $this->tell(User::where('role', Role::DEAN_PGR)->get(), new ReVivaUpdate('dean_approval', $application, "Re-examination letters awaiting your approval: {$name}",
            ["The re-examination letters and report forms for {$name} are ready for your approval."],
            'Open Re-viva Approval', route('reviva.queue', ['stage' => 'dean_approval'])));
    }

    public function deanApprove(Application $application, User $actor): void
    {
        $detail = $this->at($application, 'dean_approval');
        $signature = \App\Modules\Hani\Models\ReVivaSignature::forUser($actor->id)?->dataUri();

        if (! $signature) {
            throw ValidationException::withMessages(['signature' => 'Upload your Re-viva Correction Letters signature on My Signatures before approving.']);
        }

        DB::transaction(function () use ($application, $actor, $detail, $signature) {
            $detail->update(['dean_approved_at' => now()]);
            $this->pack->renderLetters($detail, false, $signature);
            $this->engine->decide($application, $actor, 'approve', 'Approved by the Dean of PGR.');
        });

        $name = $application->student->name;
        $this->tell($this->seniorExecs(), new ReVivaUpdate('dean_approved', $application, "Approved by the Dean: {$name}",
            ['Approved by the Dean — please send to the panel examiners.'],
            'Open Ready to Send', route('reviva.queue', ['stage' => 'ready_to_send'])));
    }

    /** The engine tells the Senior Executive and the student, with the reason. */
    public function deanReturn(Application $application, User $actor, string $reason): void
    {
        $this->at($application, 'dean_approval');
        $this->engine->returnTo($application, $actor, 'pack_preparation', $reason);
    }

    /**
     * One examiner's return. Open while the panel is reviewing and correctable
     * until the outcome is confirmed.
     */
    public function recordEvaluation(ReVivaDetail $detail, string $seat, array $data, ?UploadedFile $report, ?UploadedFile $signed, User $actor, bool $byExaminer): ReVivaEvaluation
    {
        $application = $detail->application;

        if ($application->status !== Application::STATUS_PENDING || ! in_array($application->current_stage, ['under_panel_review', 'reports_received', 'cgs_review'], true)) {
            throw ValidationException::withMessages(['category' => 'Re-evaluations cannot be recorded at this step.']);
        }

        $evaluation = DB::transaction(function () use ($detail, $seat, $data, $report, $signed, $actor) {
            $locked = ReVivaDetail::whereKey($detail->id)->lockForUpdate()->firstOrFail();
            $examiner = $locked->currentMains()[$seat] ?? null;

            if (! $examiner) {
                throw ValidationException::withMessages(['category' => 'That seat has no main examiner on the panel.']);
            }

            $category = (int) $data['category'];
            $fill = ['examiner_id' => $examiner->id, 'category' => $category, 'viva_again' => $category === ReVivaEvaluation::FAIL ? (bool) $data['viva_again'] : null, 'recorded_by_id' => $actor->id];

            if ($report) {
                $fill['report_document_id'] = $this->pack->storeUpload($locked, ReVivaStaffDocument::RE_EVALUATION, $seat, $examiner, $report, $actor)->id;
            }
            if ($signed) {
                $this->pack->storeUpload($locked, ReVivaStaffDocument::SIGNED_LETTER, $seat, $examiner, $signed, $actor);
            }

            return ReVivaEvaluation::updateOrCreate(['re_viva_detail_id' => $locked->id, 'seat' => $seat], $fill);
        });

        if ($byExaminer) {
            $name = $application->student->name;
            $this->tell($this->seniorExecs(), new ReVivaUpdate('evaluation_received', $application, "Re-evaluation received from {$evaluation->examiner->name} for {$name}",
                ["{$evaluation->examiner->name} has returned the re-evaluation for {$name}."],
                'Open Under Panel Review', route('reviva.queue', ['stage' => 'under_panel_review'])));
        }

        return $evaluation;
    }

    /** The Senior Executive's "Start review", once both reports are in. */
    public function startReview(Application $application, User $actor): void
    {
        $detail = $this->at($application, 'under_panel_review');

        if (! $detail->evaluationsComplete()) {
            throw ValidationException::withMessages(['review' => 'Both main examiners\' re-evaluations must be recorded first.']);
        }

        DB::transaction(function () use ($application, $actor) {
            $this->engine->decide($application, $actor, 'approve', 'Both re-evaluation reports received.');
            $this->engine->decide($application->refresh(), $actor, 'approve', 'Review started.');
        });
    }

    /** Pass (levels 1-3) or dismissed (5), with no meeting. */
    public function decideWithoutMeeting(Application $application, User $actor, int $level, ?string $remarks): void
    {
        $detail = $this->at($application, 'cgs_review');

        if (! in_array($level, [1, 2, 3, ReVivaDetail::OUTCOME_DISMISSED], true)) {
            throw ValidationException::withMessages(['outcome_level' => 'Level 4 needs a meeting or re-viva first.']);
        }
        if ($level === ReVivaDetail::OUTCOME_DISMISSED && blank($remarks)) {
            throw ValidationException::withMessages(['outcome_remarks' => 'Remarks are required to dismiss a candidate.']);
        }

        $this->finish($application, $detail, $actor, $level, $remarks);
    }

    public function scheduleMeeting(Application $application, User $actor, array $data): void
    {
        $detail = $this->at($application, 'cgs_review');

        DB::transaction(function () use ($application, $actor, $detail, $data) {
            $detail->update(['meeting_kind' => $data['meeting_kind'], 'meeting_at' => $data['meeting_at'], 'meeting_venue' => $data['meeting_venue']]);
            $this->engine->decide($application->refresh(), $actor, 'approve', 'A meeting has been scheduled.');
        });

        $this->invite($detail->refresh());
    }

    public function recordMeetingResult(Application $application, User $actor, int $level, ?string $remarks): void
    {
        $this->finish($application, $this->at($application, 'meeting_scheduled'), $actor, $level, $remarks);
    }

    protected function finish(Application $application, ReVivaDetail $detail, User $actor, int $level, ?string $remarks): void
    {
        DB::transaction(function () use ($application, $detail, $actor, $level, $remarks) {
            $this->engine->decide($application->refresh(), $actor, 'approve', 'Result recorded.');
            $this->outcomes->record($detail->refresh(), $level, $remarks, $actor);
        });
    }

    protected function invite(ReVivaDetail $detail): void
    {
        $application = $detail->application;
        $student = $application->student;
        $label = $detail->meeting_kind === self::RE_VIVA ? 'Re-viva (oral examination)' : 'Consolidation meeting';
        $emails = [];

        foreach ($detail->currentMains() as $examiner) {
            if ($email = $examiner->usableEmail()) {
                $emails[$email] = $examiner->name;
            }
        }
        if (($viva = $detail->vivaDetail())?->chair_email) {
            $emails[$viva->chair_email] = (string) $viva->chair_name;
        }

        $when = $detail->meeting_at->format('j F Y, g:i a');

        foreach ($emails as $email => $name) {
            $this->attempt(fn () => Notification::route('mail', [$email => $name])->notify(new ReVivaMeetingInvite($detail, $label)));
        }

        $this->tell(array_filter([$student, $student->supervisor]), new ReVivaUpdate('meeting', $application, "{$label} scheduled",
            $detail->meeting_kind === self::RE_VIVA
                ? ["A re-viva (oral examination) has been scheduled for {$when} at {$detail->meeting_venue}."]
                : ['A consolidation meeting of the examiners will be held. You will be told the result afterwards.'],
            'Open Re-viva', route('reviva.mine')));

        if ($detail->meeting_kind === self::RE_VIVA) {
            $this->attempt(fn () => $student->notify(new ReVivaMeetingInvite($detail, $label)));
        }
    }

    /** RFC 5545 calendar file: an all-day event when $end is null. */
    public static function ics(string $uid, string $summary, \DateTimeInterface $start, ?\DateTimeInterface $end, string $location = ''): string
    {
        $utc = fn ($t) => \Illuminate\Support\Carbon::parse($t)->utc()->format('Ymd\THis\Z');
        $escape = fn (string $v) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $v);

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//UResearch 2.0//Re-viva//EN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:'.$uid.'@uresearch.utp.edu.my', 'DTSTAMP:'.$utc(now()),
            $end ? 'DTSTART:'.$utc($start) : 'DTSTART;VALUE=DATE:'.$start->format('Ymd'),
            $end ? 'DTEND:'.$utc($end) : null,
            'SUMMARY:'.$escape($summary), $location ? 'LOCATION:'.$escape($location) : null, 'END:VEVENT', 'END:VCALENDAR',
        ]))."\r\n";
    }

    public static function seatLabel(string $seat): string
    {
        return VivaExaminerProgress::SEATS[$seat] ?? $seat;
    }

    protected function at(Application $application, string $stage): ReVivaDetail
    {
        $application->refresh();

        if ($application->status !== Application::STATUS_PENDING || $application->current_stage !== $stage) {
            throw ValidationException::withMessages(['stage' => 'This round has moved on. Refresh the page.']);
        }

        return ReVivaDetail::where('application_id', $application->id)->firstOrFail();
    }

    protected function seniorExecs()
    {
        return User::where('role', Role::SENIOR_EXEC_CGS)->get();
    }

    public function tell(iterable $users, ReVivaUpdate $notification): void
    {
        $this->attempt(fn () => Notification::send(collect($users)->filter(), $notification));
    }

    protected function attempt(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
