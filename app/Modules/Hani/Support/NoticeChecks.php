<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Support\Indexing\IndexingSource;
use App\Modules\Hani\Support\Pdf\PdfReader;
use Illuminate\Support\Facades\Storage;

/**
 * Automatic checks on a Notice of Thesis Submission's documents, so the
 * Academic Executive does not read every paper by hand (UAT 11 Nov 2026).
 * Explainable and local: each line says Pass / Warning / Not checked and
 * why. Never rejects anything; the AE decides.
 *
 * Per publication paper:
 *   - a real PDF, not encrypted, with extractable text
 *   - DOI, journal name, ISSN, year and "accepted for publication" wording
 *   - the student's name in the author list (NameNormaliser, fuzzy)
 *   - indexing, through IndexingSource (default ManualCheckSource: always
 *     "Needs manual check")
 * Then a requirement summary: papers read vs required (MSc 1; PhD one
 * Q1/Q2 WoS paper, or two WoS/ERA/Scopus papers), "Not met" only when no
 * paper could be read at all.
 */
class NoticeChecks
{
    public function __construct(
        protected PdfReader $reader,
        protected NameNormaliser $names,
        protected IndexingSource $indexing,
    ) {}

    /** @return array{results: array<int, array<string, string>>, papers: array<int, array<string, mixed>>} */
    public function run(Application $application): array
    {
        $student = $application->student;
        $detail = VivaDetail::where('application_id', $application->id)->first();
        $phd = $detail?->programme_level === VivaChecklist::PHD;
        $papers = $application->documents->where('doc_type', VivaDetail::DOC_PAPER)->values();
        $results = [];
        $found = [];
        $readable = 0;

        foreach ($papers as $i => $doc) {
            $n = $i + 1;
            $label = "Paper {$n} ({$doc->original_name})";
            $bytes = Storage::disk('local')->exists($doc->path) ? Storage::disk('local')->get($doc->path) : null;
            $facts = $bytes !== null ? $this->reader->facts($bytes) : ['is_pdf' => false, 'encrypted' => false, 'pages' => null];
            $text = null;

            if ($bytes === null) {
                $results[] = $this->line("paper{$n}_file", $label.': file', 'warning', 'The stored file is missing.');
            } elseif (! $facts['is_pdf']) {
                $results[] = $this->line("paper{$n}_file", $label.': file', 'warning', 'This is not a real PDF.');
            } elseif ($facts['encrypted']) {
                $results[] = $this->line("paper{$n}_file", $label.': file', 'warning', 'The PDF is encrypted, so its text cannot be read.');
            } elseif (($pages = $this->reader->text($bytes, 6)) === null) {
                $results[] = $this->line("paper{$n}_file", $label.': file', 'not_checked', 'Not checked automatically: no text could be extracted (a scanned image?).');
            } else {
                $text = implode("\n", $pages);
                $readable++;
                $results[] = $this->line("paper{$n}_file", $label.': file', 'pass', 'A readable PDF'.($facts['pages'] ? " of {$facts['pages']} page(s)" : '').'.');
            }

            $meta = $text !== null ? $this->details($text) : ['doi' => null, 'journal' => null, 'issn' => null, 'year' => null, 'accepted' => false];
            $meta['author_match'] = null;

            if ($text !== null) {
                $shown = array_filter([
                    $meta['doi'] ? 'DOI '.$meta['doi'] : null,
                    $meta['journal'] ? 'journal "'.$meta['journal'].'"' : null,
                    $meta['issn'] ? 'ISSN '.$meta['issn'] : null,
                    $meta['year'] ? 'year '.$meta['year'] : null,
                    $meta['accepted'] ? '"accepted for publication" wording' : null,
                ]);
                $results[] = $this->line("paper{$n}_details", $label.': details', $meta['doi'] || $meta['journal'] || $meta['issn'] ? 'pass' : 'warning',
                    $shown ? 'Detected: '.implode('; ', $shown).'.' : 'No DOI, journal name or ISSN was found.');

                $meta['author_match'] = $this->authorMatch((string) $student?->name, $text);
                $results[] = $this->line("paper{$n}_author", $label.': author', $meta['author_match'] ? 'pass' : 'warning',
                    $meta['author_match']
                        ? "{$student?->name} appears in the author list (as \"{$meta['author_match']}\")."
                        : "{$student?->name} was not found in the author list. Check the paper names the student.");
            }

            $index = $this->indexing->lookup($meta['journal'], $meta['issn']);
            $results[] = $this->line("paper{$n}_indexing", $label.': indexing', $index['status'], $index['detail']);

            $found[] = ['name' => $doc->original_name] + $meta;
        }

        $required = $phd ? 'PhD: one Q1/Q2 WoS paper, or two WoS/ERA/Scopus papers' : 'MSc: one paper';
        $results[] = match (true) {
            $papers->isEmpty() => $this->line('requirement', 'Publication requirement', 'not_met', "No publication paper is attached ({$required})."),
            $readable === 0 => $this->line('requirement', 'Publication requirement', 'not_met', $papers->count().' paper(s) attached, none readable ('.$required.'). Not met unless the AE confirms them by hand.'),
            default => $this->line('requirement', 'Publication requirement', 'not_checked',
                "{$readable} of {$papers->count()} paper(s) read; required: {$required}. Needs manual check (indexing)."),
        };

        return ['results' => $results, 'papers' => $found];
    }

    /** @return array{doi: ?string, journal: ?string, issn: ?string, year: ?int, accepted: bool} */
    public function details(string $text): array
    {
        $flat = preg_replace('/\s+/u', ' ', $text);
        $doi = preg_match('~\b(10\.\d{4,9}/[^\s"<>,;]+)~i', $flat, $m) ? rtrim($m[1], '.)') : null;
        $issn = preg_match('/\b(?:e-?)?ISSN[:\s]*([0-9]{4}-?[0-9]{3}[0-9Xx])/i', $flat, $m) ? strtoupper($m[1])
            : (preg_match('/\b([0-9]{4}-[0-9]{3}[0-9Xx])\b/', $flat, $m) ? strtoupper($m[1]) : null);
        if ($issn && ! str_contains($issn, '-')) {
            $issn = substr($issn, 0, 4).'-'.substr($issn, 4);
        }

        $journal = null;
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if (preg_match('/\b(journal|transactions|proceedings|review|letters)\b/i', $line) && mb_strlen($line) <= 150) {
                // "Journal of X, Vol. 12, 2026. ISSN ..." -> "Journal of X"
                $journal = trim(preg_split('/,|\bvol\b|\bvolume\b|\bissn\b|\(\d{4}\)/i', $line)[0], " .:-");
                $journal = preg_replace('/^journal\s*:\s*/i', '', $journal);
                break;
            }
        }

        $years = preg_match_all('/\b(19[5-9]\d|20\d{2})\b/', $flat, $m) ? array_filter(array_map('intval', $m[1]), fn ($y) => $y <= (int) now()->year + 1) : [];

        return [
            'doi' => $doi,
            'journal' => $journal ?: null,
            'issn' => $issn,
            'year' => $years ? max($years) : null,
            'accepted' => (bool) preg_match('/accepted\s+for\s+publication/i', $flat),
        ];
    }

    /** The author-list text that matches the student's name, or null. */
    public function authorMatch(string $name, string $text): ?string
    {
        $target = $this->names->normalise($name);
        if ($target === '') {
            return null;
        }

        $head = mb_substr($text, 0, 4000);
        foreach (preg_split('/\R|,|;|\band\b|&/u', $head) as $chunk) {
            $chunk = trim(preg_replace('/^(authors?|by)\s*:\s*/i', '', trim($chunk)));
            if ($chunk === '' || mb_strlen($chunk) > 80) {
                continue;
            }
            if ($this->names->looksAlike($chunk, $name)) {
                return $chunk;
            }
        }

        // Every word of the name present, in any order (initials aside).
        $words = explode(' ', $target);
        $normalisedHead = ' '.$this->names->normalise($head).' ';

        return count($words) >= 2 && collect($words)->every(fn ($w) => str_contains($normalisedHead, ' '.$w.' ')) ? $name : null;
    }

    protected function line(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
