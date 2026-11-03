<?php

namespace App\Modules\Hani\Support\Demo;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * FICTIONAL PDFs for tests and the demo seeder: a Turnitin Originality Report
 * laid out the way ULearn prints one, and a thesis of a given length. Every
 * name, title and source here is invented; source hosts are example.com /
 * example.org. Rendered with Dompdf, which is already installed.
 *
 * The report follows the real ULearn layout: the submission title, then
 * "ORIGINALITY REPORT", the big similarity index over "SIMILARITY INDEX",
 * the three breakdown figures (INTERNET SOURCES, PUBLICATIONS, STUDENT
 * PAPERS), the PRIMARY SOURCES list (rank, source, type, %), and the
 * exclusion settings in the footer. No submission date or ID: those are only
 * on a separate Digital Receipt page, added here only with $receipt.
 */
final class FictionalPdfs
{
    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $sources  [source, type, "4%" | "<1%"]
     * @param  array{date: string, id: string}|null  $receipt  a Digital Receipt page in front
     */
    public static function turnitinReport(
        string $title = 'Deep Learning-Based Anomaly Detection for Offshore Pipeline Sensor Data',
        int $index = 12,
        array $breakdown = [10, 5, 3],
        ?array $sources = null,
        array $exclusions = ['quotes' => 'On', 'bibliography' => 'On', 'matches' => '< 10 words'],
        ?array $receipt = null,
        string $font = 'Helvetica',
    ): string {
        $sources ??= [
            ['www.example.com', 'Internet Source', '4%'],
            ['Submitted to Example University of Technology', 'Student Paper', '2%'],
            ['A. Researcher, B. Writer. "Sensor streams in practice", Journal of Example Studies, 2023', 'Publication', '1%'],
            ['eprints.example.org', 'Internet Source', '1%'],
            ['Submitted to Example Polytechnic', 'Student Paper', '<1%'],
            ['repository.example.org', 'Internet Source', '<1%'],
        ];

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

        $rows = '';
        foreach ($sources as $i => [$source, $type, $percent]) {
            $rows .= '<tr><td class="rank">'.($i + 1).'</td><td>'.$e($source).'<br><span class="type">'.$e($type).'</span></td><td class="pc">'.$e($percent).'</td></tr>';
        }

        $receiptPage = $receipt ? '<div class="page"><h2>Digital Receipt</h2>'
            .'<p>This receipt acknowledges that Turnitin received your paper.</p>'
            .'<p>Submission author: A. Student</p>'
            .'<p>Assignment title: Thesis Similarity Check</p>'
            .'<p>Submission title: '.$e($title).'</p>'
            .'<p>Submission date: '.$e($receipt['date']).'</p>'
            .'<p>Submission ID: '.$e($receipt['id']).'</p></div><div class="break"></div>' : '';

        $html = '<html><head><style>
            body { font-family: "'.$font.'"; font-size: 11pt; }
            .title { font-size: 14pt; font-weight: bold; margin-bottom: 10pt; }
            .label { font-size: 9pt; letter-spacing: 1pt; }
            .big { font-size: 28pt; font-weight: bold; }
            table.figures td { width: 25%; text-align: center; vertical-align: top; }
            table.sources { width: 100%; border-collapse: collapse; margin-top: 8pt; }
            table.sources td { border-top: 1px solid #ccc; padding: 4pt; }
            .rank { width: 24pt; } .pc { width: 40pt; text-align: right; font-weight: bold; }
            .type { font-size: 8pt; color: #555; }
            .footer { margin-top: 16pt; font-size: 8pt; }
            .break { page-break-after: always; }
        </style></head><body>'.$receiptPage.'
            <div class="title">'.$e($title).'</div>
            <div class="label">ORIGINALITY REPORT</div>
            <table class="figures"><tr>
                <td><span class="big">'.$index.'%</span><br><span class="label">SIMILARITY INDEX</span></td>
                <td><span class="big">'.$breakdown[0].'%</span><br><span class="label">INTERNET SOURCES</span></td>
                <td><span class="big">'.$breakdown[1].'%</span><br><span class="label">PUBLICATIONS</span></td>
                <td><span class="big">'.$breakdown[2].'%</span><br><span class="label">STUDENT PAPERS</span></td>
            </tr></table>
            <div class="label">PRIMARY SOURCES</div>
            <table class="sources">'.$rows.'</table>
            <div class="footer">
                Exclude quotes '.$e($exclusions['quotes']).' &nbsp; Exclude matches '.$e($exclusions['matches']).'<br>
                Exclude bibliography '.$e($exclusions['bibliography']).'
            </div>
        </body></html>';

        return self::render($html);
    }

    /** A fictional thesis: a title page, then body pages of filler words. */
    public static function thesis(string $title = 'Deep Learning-Based Anomaly Detection for Offshore Pipeline Sensor Data', int $words = 1200, int $pages = 3, string $author = 'A. Student'): string
    {
        $vocabulary = ['sensor', 'pipeline', 'anomaly', 'detection', 'model', 'data', 'offshore', 'signal', 'network', 'method', 'result', 'analysis'];
        $perPage = (int) ceil($words / max(1, $pages - 1));
        $body = '';
        $written = 0;

        for ($p = 1; $p < $pages; $p++) {
            $n = min($perPage, $words - $written);
            $text = implode(' ', array_map(fn ($i) => $vocabulary[($i * 7 + $p) % count($vocabulary)], range(1, max(1, $n))));
            $written += $n;
            $body .= '<div class="break"></div><p>'.$text.'</p>';
        }

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);

        return self::render('<html><head><style>
            body { font-family: "Helvetica"; font-size: 11pt; }
            .break { page-break-before: always; }
            h1 { text-align: center; margin-top: 120pt; font-size: 18pt; }
            p.c { text-align: center; }
        </style></head><body>
            <h1>'.$e(mb_strtoupper($title)).'</h1>
            <p class="c">by</p><p class="c">'.$e($author).'</p>
            <p class="c">A thesis submitted in fulfilment of the requirements for the degree</p>
            '.$body.'
        </body></html>');
    }

    private static function render(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
