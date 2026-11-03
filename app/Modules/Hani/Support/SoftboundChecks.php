<?php

namespace App\Modules\Hani\Support;

use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Support\Pdf\PdfReader;
use App\Modules\Hani\Support\Similarity\SimilarityReading;

/**
 * The "System checks" on a softbound attempt: what a person at CGS would
 * otherwise check by opening both PDFs. Each result says what was found, so
 * the AE can see why a check passed or warned:
 *
 *   ['key' => ..., 'label' => ..., 'status' => pass | warning | not_checked | info, 'detail' => ...]
 *
 * Nothing here blocks. The hard limits (similarity, words) are enforced on
 * the form; these are for the AE's judgement.
 */
class SoftboundChecks
{
    public function __construct(protected PdfReader $pdf) {}

    /**
     * @return array<int, array{key: string, label: string, status: string, detail: string}>
     */
    public function run(VivaSoftboundDetail $detail, ?SimilarityReading $reading, ?string $reportBytes, ?string $thesisBytes, ?string $noticeTitle, ?string $level): array
    {
        $out = [];
        $declared = (float) $detail->similarity_percent;
        $title = $noticeTitle ?: $detail->thesis_title;

        // ---- The Turnitin report -------------------------------------------
        $out[] = $this->fileCheck('report_file', 'Turnitin report file', $reportBytes);

        if ($reading?->ok) {
            $figures = sprintf('%s%% similarity index (internet sources %s, publications %s, student papers %s)',
                self::pc($reading->index), self::pcOrDash($reading->internet), self::pcOrDash($reading->publications), self::pcOrDash($reading->studentPapers));
            $mismatch = abs($declared - $reading->index) > (float) config('hani.similarity.mismatch_points', 1.0);

            $out[] = $this->result('similarity_read', 'Similarity read from the report',
                $mismatch ? 'warning' : 'pass',
                ($reading->source === 'simulated' ? 'Simulated: ' : 'Read: ').$figures.'.'
                .($mismatch ? ' The student declared '.self::pc($declared).'%, which does not match.' : ' Matches the declared figure.'));

            $out[] = $reading->title
                ? $this->result('report_title', 'Report title against the notice',
                    self::titleMatches($title, $reading->title) ? 'pass' : 'warning',
                    'Report title: "'.$reading->title.'".'.(self::titleMatches($title, $reading->title) ? '' : ' It does not match the notice title "'.$title.'".'))
                : $this->result('report_title', 'Report title against the notice', 'not_checked', 'No submission title found in the report.');

            $limit = (float) config('hani.similarity.single_source_warn', 5);
            $large = array_filter($reading->sources, fn ($s) => $s['value'] > $limit);
            $out[] = $reading->sources === []
                ? $this->result('single_source', 'Largest single source', 'not_checked', 'No primary sources could be read from the report.')
                : $this->result('single_source', 'Largest single source', $large ? 'warning' : 'pass', $large
                    ? 'Above '.self::pc($limit).'%: '.implode('; ', array_map(fn ($s) => "{$s['rank']}. {$s['source']} ({$s['type']}, {$s['percent']})", $large)).'.'
                    : 'No single source above '.self::pc($limit).'%. Top: '.$reading->sources[0]['source'].' ('.$reading->sources[0]['percent'].').');

            $ex = $reading->exclusions;
            $out[] = $this->result('exclusions', 'Exclusion settings (as printed)', 'info', sprintf('Exclude quotes %s · Exclude bibliography %s · Exclude matches %s',
                $ex['quotes'] ?? 'not shown', $ex['bibliography'] ?? 'not shown', $ex['matches'] ?? 'not shown'));

            $date = $reading->submittedOn();
            if ($date === null) {
                $out[] = $this->result('report_age', 'Report age', 'not_checked', 'Not checked — no submission date in this report.');
            } else {
                $days = (int) $date->copy()->startOfDay()->diffInDays($detail->created_at ?? now());
                $max = (int) config('hani.similarity.max_report_age_days', 30);
                $out[] = $this->result('report_age', 'Report age', $days > $max ? 'warning' : 'pass',
                    "Digital Receipt dated {$date->format('j M Y')}, {$days} days before submission"
                    .($reading->submissionId ? " (submission ID {$reading->submissionId})" : '').'.'
                    .($days > $max ? " Older than {$max} days." : ''));
            }
        } else {
            $out[] = $this->result('similarity_read', 'Similarity read from the report', 'not_checked',
                'Not checked automatically'.($reading?->failure ? ': '.rtrim($reading->failure, '.') : '').'. The student\'s declared '.self::pc($declared).'% is used (Self-declared).');
        }

        // ---- The thesis -----------------------------------------------------
        $out[] = $thesisCheck = $this->fileCheck('thesis_file', 'Thesis file', $thesisBytes);

        $pages = $thesisCheck['status'] === 'pass' ? $this->pdf->text($thesisBytes) : null;
        $facts = $thesisBytes !== null ? $this->pdf->facts($thesisBytes) : null;

        if ($facts && $facts['pages']) {
            $out[] = $this->result('page_count', 'Page count', 'info', number_format($facts['pages']).' pages.');
        }

        if ($pages === null) {
            $out[] = $this->result('thesis_text', 'Thesis text', 'not_checked',
                'Not checked automatically: the text could not be read (a scanned or unusual PDF). Word count and title were not checked.');

            return $out;
        }

        $out[] = $this->result('thesis_text', 'Thesis text', 'pass', 'Text read with the '.$this->pdf->engine().'.');

        $counted = VivaDetail::wordCount(implode("\n", $pages));
        $declaredWords = (int) $detail->word_count;
        $tolerance = (float) config('hani.softbound_checks.word_tolerance', 0.15);
        $limit = VivaSoftboundDetail::wordLimitFor($level);
        $far = $declaredWords > 0 && abs($counted - $declaredWords) > $declaredWords * $tolerance;
        $over = $counted > $limit;

        $out[] = $this->result('word_count', 'Word count', ($far || $over) ? 'warning' : 'pass',
            'About '.number_format($counted).' words counted in the PDF (everything, including front matter and references); declared '.number_format($declaredWords).'.'
            .($far ? ' More than '.(int) round($tolerance * 100).'% apart.' : '')
            .($over ? ' Above the '.number_format($limit).'-word limit.' : ''));

        $opening = implode("\n", array_slice($pages, 0, (int) config('hani.softbound_checks.title_pages', 2)));
        $out[] = $this->result('thesis_title', 'Title on the opening pages',
            self::titleMatches($title, $opening) ? 'pass' : 'warning',
            self::titleMatches($title, $opening)
                ? 'The notice title appears on the opening pages.'
                : 'The notice title "'.$title.'" was not found on the first '.(int) config('hani.softbound_checks.title_pages', 2).' pages.');

        return $out;
    }

    /** Is it a PDF a person could open? */
    protected function fileCheck(string $key, string $label, ?string $bytes): array
    {
        if ($bytes === null) {
            return $this->result($key, $label, 'not_checked', 'The file could not be opened from storage.');
        }

        $facts = $this->pdf->facts($bytes);

        return match (true) {
            ! $facts['is_pdf'] => $this->result($key, $label, 'warning', 'Not a real PDF (its contents do not start like one), whatever its name says.'),
            $facts['encrypted'] => $this->result($key, $label, 'warning', 'The PDF is password-protected or encrypted.'),
            default => $this->result($key, $label, 'pass', 'A readable PDF, not encrypted.'),
        };
    }

    protected function result(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }

    /**
     * Do most of the title's meaningful words appear in the text? Case,
     * punctuation and line breaks are ignored.
     */
    public static function titleMatches(?string $title, ?string $text): bool
    {
        $words = fn (?string $s) => array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $s)),
            fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['the', 'and', 'for', 'with', 'from', 'into', 'using'], true),
        );

        $wanted = array_unique($words($title));
        if ($wanted === []) {
            return false;
        }

        $have = array_flip($words($text));
        $found = count(array_filter($wanted, fn ($w) => isset($have[$w])));

        return $found / count($wanted) >= (float) config('hani.softbound_checks.title_overlap', 0.8);
    }

    public static function pc(?float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    protected static function pcOrDash(?float $value): string
    {
        return $value === null ? 'not shown' : self::pc($value).'%';
    }
}
