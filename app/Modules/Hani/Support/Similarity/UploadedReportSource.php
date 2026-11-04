<?php

namespace App\Modules\Hani\Support\Similarity;

use App\Modules\Hani\Support\Pdf\PdfReader;

/**
 * Reads the Turnitin Originality Report a student downloads from ULearn.
 *
 * The layout it expects, as ULearn prints it:
 *
 *     <submission title>
 *     ORIGINALITY REPORT
 *     12%            10%               5%             3%
 *     SIMILARITY INDEX  INTERNET SOURCES  PUBLICATIONS  STUDENT PAPERS
 *     PRIMARY SOURCES
 *     1  www.example.com                     Internet Source   4%
 *     2  Submitted to <institution>          Student Paper     2%
 *     ...
 *     Exclude quotes On    Exclude matches < 10 words
 *     Exclude bibliography On
 *
 * The report carries no submission date or ID; those are on the separate
 * Digital Receipt, and are read only if a receipt page is in the same PDF.
 * The figures are matched in reading order, so it copes with the numbers
 * printed above their labels or beside them.
 */
class UploadedReportSource implements SimilaritySource
{
    /** How many primary sources are kept. */
    public const TOP_SOURCES = 5;

    public function __construct(protected PdfReader $pdf) {}

    public function name(): string
    {
        return 'uploaded';
    }

    public function read(string $reportBytes): SimilarityReading
    {
        $facts = $this->pdf->facts($reportBytes);

        if (! $facts['is_pdf']) {
            return SimilarityReading::failed($this->name(), 'The file is not a PDF.', $this->pdf->engine());
        }

        if ($facts['encrypted']) {
            return SimilarityReading::failed($this->name(), 'The PDF is password-protected, so it cannot be read.', $this->pdf->engine());
        }

        $pages = $this->pdf->text($reportBytes);

        if ($pages === null) {
            return SimilarityReading::failed($this->name(), 'The text of this report could not be read automatically.', $this->pdf->engine());
        }

        return $this->parse($pages, $this->pdf->engine());
    }

    /**
     * @param  array<int, string>  $pages
     */
    public function parse(array $pages, ?string $engine = null): SimilarityReading
    {
        $text = self::normalise(implode("\n", $pages));

        $reportAt = stripos($text, 'ORIGINALITY REPORT');
        if ($reportAt === false || stripos($text, 'SIMILARITY INDEX') === false) {
            return SimilarityReading::failed($this->name(),
                'This does not look like a Turnitin Originality Report. Download the full similarity report from ULearn, not the Digital Receipt alone.', $engine);
        }

        $sourcesAt = stripos($text, 'PRIMARY SOURCES', $reportAt);
        $figures = substr($text, $reportAt, ($sourcesAt === false ? strlen($text) : $sourcesAt) - $reportAt);
        preg_match_all('/(\d{1,3}(?:\.\d+)?)\s*%/', $figures, $percents);
        $numbers = array_map('floatval', $percents[1]);

        if ($numbers === [] || $numbers[0] > 100) {
            return SimilarityReading::failed($this->name(), 'The similarity index could not be found in this report.', $engine);
        }

        return new SimilarityReading(
            ok: true,
            source: $this->name(),
            title: $this->title($text, $reportAt),
            index: $numbers[0],
            internet: $numbers[1] ?? null,
            publications: $numbers[2] ?? null,
            studentPapers: $numbers[3] ?? null,
            sources: $sourcesAt === false ? [] : $this->sources(substr($text, $sourcesAt + strlen('PRIMARY SOURCES'))),
            exclusions: [
                'quotes' => preg_match('/Exclude quotes\s+(On|Off)\b/i', $text, $m) ? ucfirst(strtolower($m[1])) : null,
                'bibliography' => preg_match('/Exclude bibliography\s+(On|Off)\b/i', $text, $m) ? ucfirst(strtolower($m[1])) : null,
                'matches' => preg_match('/Exclude matches\s+(Off\b|<\s*\d+\s*(?:words|%))/i', $text, $m) ? preg_replace('/\s+/', ' ', $m[1]) : null,
            ],
            submittedAt: preg_match('/Submission date:\s*([^\n]+)/i', $text, $m) ? trim($m[1]) : null,
            submissionId: preg_match('/Submission ID:\s*([0-9A-Za-z:\-]+)/i', $text, $m) ? $m[1] : null,
            engine: $engine,
        );
    }

    /** The lines just above ORIGINALITY REPORT, on the report page (not the receipt). */
    protected function title(string $text, int $reportAt): ?string
    {
        $before = substr($text, 0, $reportAt);

        // Start after the receipt page if there is one.
        if (preg_match('/Submission ID:[^\n]*\n/i', $before, $m, PREG_OFFSET_CAPTURE)) {
            $before = substr($before, $m[0][1] + strlen($m[0][0]));
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $before)), fn ($l) => $l !== ''));
        // A long title wraps; three lines is plenty.
        $title = trim(implode(' ', array_slice($lines, -3)));

        return $title === '' ? null : mb_substr($title, 0, 255);
    }

    /**
     * rank, source, type, % -- in order, ranks counting up from 1.
     *
     * @return array<int, array{rank: int, source: string, type: string, percent: string, value: float}>
     */
    protected function sources(string $text): array
    {
        $cut = stripos($text, 'Exclude quotes');
        $flat = preg_replace('/\s+/u', ' ', $cut === false ? $text : substr($text, 0, $cut));

        preg_match_all('/(?:^|\s)(\d{1,3})\s+(.+?)\s+(Internet Source|Publication|Student Paper)\s+(<\s*1|\d{1,3})\s*%/u', $flat, $rows, PREG_SET_ORDER);

        $out = [];
        $expected = 1;

        foreach ($rows as [, $rank, $source, $type, $percent]) {
            if ((int) $rank !== $expected) {
                continue;
            }

            $lessThanOne = str_starts_with($percent, '<');
            $out[] = [
                'rank' => (int) $rank,
                'source' => mb_substr(trim($source), 0, 255),
                'type' => $type,
                'percent' => ($lessThanOne ? '<1' : $percent).'%',
                'value' => $lessThanOne ? 0.5 : (float) $percent,
            ];

            if (++$expected > self::TOP_SOURCES) {
                break;
            }
        }

        return $out;
    }

    protected static function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);

        return preg_replace('/[ \t]+/u', ' ', $text);
    }
}
