<?php

namespace App\Modules\Nureen\Console\Commands;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Support\CertificateReferenceGenerator;
use App\Modules\Nureen\Support\LetterRenderer;
use App\Modules\Nureen\Support\PdfText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Re-issues GA/GRA certification letters that an earlier template made.
 *
 * The first certification template printed "Ref: UResearch/GA/<id>", said
 * it was "digitally endorsed through UResearch 2.0", and had no UTP logo.
 * Letters stored then are still what students download. This finds them,
 * plus any letter whose application never got a CGS/GACERT reference, and
 * renders them again with today's LetterRenderer.
 *
 * What it keeps, on purpose:
 *  - the ApplicationDocument row and its stored path. The new PDF is written
 *    over the old file, so every link, email and Documents-page entry that
 *    points at the letter still works.
 *  - the signatory and date. LetterRenderer reads both from the Senior
 *    Director's row in approval_history, exactly as it does on approval.
 *  - an existing CGS/GACERT reference. Only a missing (or non-CGS) one is
 *    assigned, with CertificateReferenceGenerator.
 *
 * It sends NOTHING. No notification, no mail: this corrects a document on
 * file, it is not a new issue. Approved applications with no letter on file
 * are listed and left alone -- there is no document to replace.
 *
 * Safe to run twice: a letter the current template made has none of the
 * old markers and a CGS reference, so the second run finds nothing.
 */
class RegenerateCertificationLetters extends Command
{
    protected $signature = 'nureen:regenerate-certification-letters
        {--dry-run : List what would change, and change nothing}';

    protected $description = 'Re-render GA/GRA certification letters made by the old template, in place, without emailing anyone';

    /** Whitespace-free text only the old template printed (see PdfText). */
    public const OLD_TEMPLATE_MARKERS = [
        'Ref:UResearch/',
        'endorsedthroughUResearch',
    ];

    public function handle(CertificateReferenceGenerator $references, LetterRenderer $letters): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = config('nureen.ga_certification.reference_prefix').'/';

        $applications = Application::where('module_type', 'ga_certification')
            ->where('status', Application::STATUS_APPROVED)
            ->orderBy('id')
            ->get();

        $planned = [];
        $skipped = [];

        foreach ($applications as $application) {
            $detail = GaCertificationDetail::where('application_id', $application->id)->first();
            $document = $application->documents()
                ->where('doc_type', LetterRenderer::CERTIFICATION_DOC_TYPE)
                ->latest('id')
                ->first();

            if (! $detail || ! $document) {
                $skipped[] = [$application->reference(), ! $detail ? 'No certification details' : 'No letter on file to replace'];

                continue;
            }

            $reasons = $this->reasons($detail, $document, $prefix);

            if ($reasons === []) {
                continue;
            }

            // The letter is signed by whoever approved; with no such row it
            // would print without a signatory, which is worse than the old one.
            $approved = ApprovalHistory::where('application_id', $application->id)
                ->where('stage_key', 'senior_director')
                ->where('decision', 'approved')
                ->exists();

            if (! $approved) {
                $skipped[] = [$application->reference(), 'No Senior Director approval in approval_history'];

                continue;
            }

            $planned[] = compact('application', 'detail', 'document', 'reasons');
        }

        if ($planned === []) {
            $this->info('No certification letter needs regenerating.');
        } else {
            $rows = [];

            foreach ($planned as $item) {
                $old = $item['detail']->reference_number;
                $needsReference = ! $this->hasCgsReference($old, $prefix);

                $new = $dryRun ? null : $this->regenerate($item, $references, $letters, $needsReference);

                $rows[] = [
                    $item['application']->reference(),
                    $item['application']->student?->name ?? '—',
                    $item['document']->id,
                    implode('; ', $item['reasons']),
                    $needsReference
                        ? ($dryRun ? ($old ? "{$old} → new reference" : 'new reference') : ($old ? "{$old} → {$new}" : $new))
                        : $old,
                ];
            }

            $this->table(['Application', 'Student', 'Document', 'Why', 'Reference'], $rows);
            $this->info($dryRun
                ? count($planned).' letter(s) would be regenerated. Dry run: nothing was changed.'
                : count($planned).' letter(s) regenerated in place. No one was emailed.');
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->warn('Left alone:');
            $this->table(['Application', 'Why'], $skipped);
        }

        return self::SUCCESS;
    }

    /** @return list<string> Why this letter needs regenerating; empty if it does not. */
    protected function reasons(GaCertificationDetail $detail, ApplicationDocument $document, string $prefix): array
    {
        $reasons = [];

        if (! $this->hasCgsReference($detail->reference_number, $prefix)) {
            $reasons[] = 'no CGS/GACERT reference';
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($document->path)) {
            $reasons[] = 'stored file missing';
        } else {
            $text = PdfText::compact((string) $disk->get($document->path));

            foreach (self::OLD_TEMPLATE_MARKERS as $marker) {
                if (str_contains($text, $marker)) {
                    $reasons[] = 'old template';
                    break;
                }
            }
        }

        return $reasons;
    }

    protected function hasCgsReference(?string $reference, string $prefix): bool
    {
        return $reference !== null && str_starts_with($reference, $prefix);
    }

    /**
     * Assigns a reference if asked, renders, and writes over the stored file.
     * The reference and the file go together: if rendering fails, the
     * reference is rolled back and the old file is untouched.
     *
     * @param  array{application: Application, detail: GaCertificationDetail, document: ApplicationDocument}  $item
     */
    protected function regenerate(array $item, CertificateReferenceGenerator $references, LetterRenderer $letters, bool $assignReference): ?string
    {
        return DB::transaction(function () use ($item, $references, $letters, $assignReference) {
            if ($assignReference) {
                $item['detail']->update(['reference_number' => $references->generate()]);
            }

            $pdf = $letters->certification($item['application']->fresh());

            // The same row and path, so every existing link keeps working.
            Storage::disk('local')->put($item['document']->path, $pdf);
            $item['document']->update(['size_bytes' => strlen($pdf)]);

            return $item['detail']->fresh()->reference_number;
        });
    }
}
