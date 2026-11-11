<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Notifications\ReVivaUpdate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Levels 1-3 after the outcome: the student's final thesis, the Senior Executive's
 * "Corrections accepted", and the pre-filled Confirmation of Correction
 * (examiners 1 and 2 and the chairman sign it), stored staff-only.
 */
class ReVivaCorrections
{
    public const DOC_FINAL = 'Final Thesis (Re-viva)';

    public function __construct(protected DocumentStore $documents) {}

    public function submit(ReVivaDetail $detail, UploadedFile $thesis, User $student): ReVivaDetail
    {
        $detail = DB::transaction(function () use ($detail, $thesis) {
            $locked = ReVivaDetail::with('application')->whereKey($detail->id)->lockForUpdate()->firstOrFail();

            if (! $locked->needsFinalThesis() || $locked->corrections_accepted_at !== null) {
                throw ValidationException::withMessages(['final_thesis' => 'There is no final thesis to submit for this re-viva.']);
            }
            if (! $locked->finalThesisOpen()) {
                throw ValidationException::withMessages(['final_thesis' => 'You may submit your final thesis from '.$locked->finalOpensOn()->format('j M Y').'.']);
            }

            $this->documents->attach($locked->application, $thesis, self::DOC_FINAL);
            $locked->update(['final_thesis_submitted_at' => now()]);

            return $locked;
        });

        $this->tell(ReVivaRecipients::cgs($detail->application), (new ReVivaUpdate(
            'final_thesis_submitted', $detail->application,
            'Final thesis submitted: '.$student->name,
            ["{$student->name} has submitted the final thesis for re-viva round {$detail->cycle_number} ({$detail->outcomeLabel()})."],
            'Open Re-viva Outcomes', route('reviva.outcomes.index'),
        ))->forCgs());

        return $detail;
    }

    public function accept(ReVivaDetail $detail, User $actor): ReVivaStaffDocument
    {
        [$detail, $confirmation] = DB::transaction(function () use ($detail, $actor) {
            $locked = ReVivaDetail::with('application.student.supervisor')->whereKey($detail->id)->lockForUpdate()->firstOrFail();

            if (! $locked->needsFinalThesis() || $locked->final_thesis_submitted_at === null) {
                throw ValidationException::withMessages(['corrections' => 'The student has not submitted the final thesis yet.']);
            }
            if ($locked->corrections_accepted_at !== null) {
                throw ValidationException::withMessages(['corrections' => 'These corrections were already accepted.']);
            }

            $locked->update(['corrections_accepted_at' => now(), 'corrections_accepted_by_id' => $actor->id]);

            $path = 'hani/reviva-confirmations/'.Str::random(40).'.pdf';
            Storage::disk('local')->put($path, self::render($locked));

            $confirmation = ReVivaStaffDocument::create([
                're_viva_detail_id' => $locked->id,
                'kind' => ReVivaStaffDocument::CONFIRMATION,
                'path' => $path,
                'original_name' => 'confirmation-of-correction-'.Str::slug($locked->application->student->matric_no ?: $locked->application->student->name).'.pdf',
                'created_by_id' => $actor->id,
            ]);

            activity('re_viva')->causedBy($actor)->performedOn($locked->application)
                ->withProperties(['action' => 'corrections_accepted'])
                ->log('Re-viva corrections accepted');

            return [$locked, $confirmation];
        });

        $this->tell(array_filter([$detail->application->student, $detail->application->student->supervisor]), new ReVivaUpdate(
            'corrections_accepted', $detail->application,
            'Re-viva corrections accepted',
            ["CGS has accepted the final thesis for {$detail->application->student->name}'s re-viva (round {$detail->cycle_number}). The re-viva is complete."],
            'View the re-viva', route('applications.show', $detail->application),
        ));

        return $confirmation;
    }

    public static function render(ReVivaDetail $detail): string
    {
        $student = $detail->application->student;
        $viva = $detail->vivaDetail();
        $mains = $detail->currentMains();
        $phd = ($viva?->programme_level ?? VivaChecklist::levelFor($student)) === VivaChecklist::PHD;
        $place = fn (?Examiner $e) => $e ? ($e->type === Examiner::TYPE_EXTERNAL ? $e->institution : $e->department) : null;

        $data = [
            'student' => $student,
            'supervisor' => $student->supervisor?->name,
            'programme' => $student->programme ?: '-',
            'degreeName' => $phd ? 'Doctor of Philosophy' : 'Master of Science',
            'thesisTitle' => $viva?->thesis_title ?: ($detail->panel()?->thesis_title ?: '-'),
            'examiners' => [
                ['Examiner 1 (Internal)', $mains['internal_main_id']->name ?? '-', $place($mains['internal_main_id'] ?? null)],
                ['Examiner 2 (External)', $mains['external_main_id']->name ?? '-', $place($mains['external_main_id'] ?? null)],
                ['Chairman', $viva?->chair_name ?: '-', null],
            ],
            'round' => $detail->cycle_number,
            'levelLabel' => $detail->outcomeLabel(),
            'acceptedAt' => $detail->corrections_accepted_at ?? now(),
            'institution' => config('hani.reviva.letter.institution', 'Universiti Teknologi PETRONAS'),
        ];

        return Pdf::loadView('hani::re_viva.pdf.confirmation_of_correction', $data)
            ->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Serif', 'isRemoteEnabled' => false])
            ->output();
    }

    protected function tell(iterable $users, ReVivaUpdate $notification): void
    {
        try {
            Notification::send(collect($users)->filter(), $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
