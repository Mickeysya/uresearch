<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\ReVivaStaffDocument;
use App\Modules\Hani\Models\VivaDetail;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The re-viva's PDFs: one letter and one UTP/CGS/024 per main examiner, and
 * the three student forms. Files naming the examiners stay on the staff-only
 * private disk, because Core shows students every DocumentStore file.
 */
class ReVivaDocuments
{
    public const FORMS = ['status' => 'Status of Thesis', 'summary' => 'Summary of Corrections', 'confirmation' => 'Confirmation of Correction'];

    /** Generate or regenerate the four PDFs while the pack is being prepared. */
    public function generate(ReVivaDetail $detail, User $actor): void
    {
        $old = $detail->staffDocuments()->whereIn('kind', [ReVivaStaffDocument::LETTER, ReVivaStaffDocument::REPORT_FORM])->get();
        Storage::disk('local')->delete($old->pluck('path')->all());
        $old->each->delete();

        foreach ($detail->currentMains() as $seat => $examiner) {
            $slug = Str::slug($examiner->name);
            $this->store($detail, ReVivaStaffDocument::LETTER, $seat, $examiner, $this->letter($detail, $examiner, true), "correction-letter-{$slug}.pdf", $actor->id);
            $this->store($detail, ReVivaStaffDocument::REPORT_FORM, $seat, $examiner, $this->reportForm($detail, $examiner), "utp-cgs-024-{$slug}.pdf", $actor->id);
        }
    }

    /** Re-render the letters in place: the final version after the Dean approves. */
    public function renderLetters(ReVivaDetail $detail, bool $draft, ?string $signature = null, ?User $approver = null): void
    {
        foreach ($detail->staffDocuments()->where('kind', ReVivaStaffDocument::LETTER)->with('examiner')->get() as $letter) {
            Storage::disk('local')->put($letter->path, $this->letter($detail, $letter->examiner, $draft, $signature, $approver));
        }
    }

    public function storeUpload(ReVivaDetail $detail, string $kind, string $seat, Examiner $examiner, UploadedFile $file, User $actor): ReVivaStaffDocument
    {
        $document = ReVivaStaffDocument::create([
            're_viva_detail_id' => $detail->id, 'kind' => $kind, 'seat' => $seat, 'examiner_id' => $examiner->id,
            'path' => $file->store('hani/reviva-reports', 'local'),
            'original_name' => $file->getClientOriginalName() ?: $kind.'.pdf', 'created_by_id' => $actor->id,
        ]);

        activity('document')->causedBy($actor)->performedOn($document)->withProperties(['action' => 'uploaded', 'kind' => $kind])->log('Re-viva examiner return uploaded');

        return $document;
    }

    protected function store(ReVivaDetail $detail, string $kind, string $seat, Examiner $examiner, string $pdf, string $name, int $by): void
    {
        $path = 'hani/reviva-letters/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($path, $pdf);
        ReVivaStaffDocument::create(['re_viva_detail_id' => $detail->id, 'kind' => $kind, 'seat' => $seat, 'examiner_id' => $examiner->id, 'path' => $path, 'original_name' => $name, 'created_by_id' => $by]);
    }

    public function letter(ReVivaDetail $detail, Examiner $examiner, bool $draft, ?string $signature = null, ?User $approver = null): string
    {
        $data = self::candidate($detail->application->student, $detail->vivaDetail(), $detail);
        $days = ReVivaLetters::panelWorkingDays($detail);

        return $this->pdf('correction_letter', $data + [
            'reference' => config('hani.reviva.letter.reference_prefix').($data['student']->matric_no ?: $data['student']->id),
            'date' => now(), 'examiner' => $examiner, 'examinerPlace' => self::placeOf($examiner),
            'workingDays' => $days, 'workingDaysWords' => ReVivaLetters::words($days),
            'letter' => (array) config('hani.reviva.letter'),
            'contacts' => (array) config('hani.reviva.return_contacts'),
            'signatory' => self::signatory($detail, $approver),
            'round' => $detail->cycle_number, 'draft' => $draft, 'signatureImage' => $draft ? null : $signature,
        ]);
    }

    /**
     * Who signs the letter: the Dean of PGR who approved it ($approver while
     * approving, then the approval_history row), with the configured title.
     * The configured name is only the fallback before anyone has approved.
     *
     * @return array{name: string, title: array<int, string>}
     */
    public static function signatory(ReVivaDetail $detail, ?User $approver = null): array
    {
        $config = (array) config('hani.reviva.signatory');
        $approver ??= \App\Modules\Core\Models\ApprovalHistory::query()
            ->where('application_id', $detail->application_id)
            ->where('stage_key', 'dean_approval')
            ->whereNotIn('decision', ['rejected', 'returned'])
            ->latest('id')->first()?->approver;

        return ['name' => $approver?->name ?: (string) ($config['name'] ?? ''), 'title' => (array) ($config['title'] ?? [])];
    }

    public function reportForm(ReVivaDetail $detail, Examiner $examiner): string
    {
        return $this->pdf('re_evaluation_report', self::candidate($detail->application->student, $detail->vivaDetail(), $detail) + [
            'examinerName' => $examiner->name, 'plos' => (array) config('hani.reviva.plo_texts'),
        ]);
    }

    /** One of the student's forms: pre-filled for them, or the fictional example. */
    public function form(string $form, string $variant, ?User $student): string
    {
        return $this->pdf('forms.'.$form, $variant === 'example' ? ReVivaExampleForm::data() : self::blankData($student));
    }

    protected function blankData(User $student): array
    {
        $viva = ReVivaWindow::categoryFourViva($student);
        $latest = ReVivaLogging::latestCycle($student->id);
        $panel = $latest?->panel() ?? ($viva ? VivaProgress::forNotices(collect([$viva->application]))->first()?->panel() : null);
        $base = self::candidate($student, $viva ?? $latest?->vivaDetail(), $latest);

        return $base + [
            'example' => false, 'name' => $student->name, 'matric' => $student->matric_no ?: '', 'supervisor' => $student->supervisor?->name ?: '',
            'coSupervisor' => ($viva ?? $latest?->vivaDetail())?->co_supervisor_name ?: '',
            'examiner1' => $panel?->internalMain?->name ?: '', 'examiner2' => $panel?->externalMain?->name ?: '',
            'chairman' => ($viva ?? $latest?->vivaDetail())?->chair_name ?: '',
            'rcsDate' => ($viva ?? $latest?->vivaDetail())?->viva_at?->format('j F Y') ?: '',
            'address' => '', 'date' => '', 'confidential' => null, 'internalRows' => [], 'externalRows' => [],
        ];
    }

    /** @return array{student: User, phd: bool, degreeWord: string, degreeName: string, programme: string, thesisTitle: string, studyMode: ?string} */
    public static function candidate(User $student, ?VivaDetail $viva, ?ReVivaDetail $detail): array
    {
        $phd = ($viva?->programme_level ?? VivaChecklist::levelFor($student)) === VivaChecklist::PHD;
        $programme = (string) ($student->department ?: '-');
        $stored = strtolower((string) $student->programme);
        $level = preg_match('/mphil/', $stored) ? 'MPhil' : ($phd ? 'PhD' : 'MSc');

        return [
            'student' => $student, 'phd' => $phd, 'degreeWord' => $phd ? 'Doctoral' : 'Master\'s',
            'degreeName' => "{$level} in {$programme}", 'programme' => $programme,
            'thesisTitle' => $viva?->thesis_title ?: ($detail?->panel()?->thesis_title ?: '-'),
            'studyMode' => str_contains($stored, 'part') ? 'Part Time' : (str_contains($stored, 'full') ? 'Full Time' : null),
        ];
    }

    /** @return array<int, string> */
    protected static function placeOf(Examiner $examiner): array
    {
        if ($examiner->type === Examiner::TYPE_EXTERNAL) {
            return array_values(array_filter([$examiner->department !== 'External' ? $examiner->department : null, $examiner->institution]));
        }

        return array_values(array_filter([$examiner->department ? 'Department of '.$examiner->department : null, config('hani.reviva.letter.institution')]));
    }

    protected function pdf(string $view, array $data): string
    {
        return Pdf::loadView('hani::re_viva.pdf.'.$view, $data)->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false])->output();
    }
}
