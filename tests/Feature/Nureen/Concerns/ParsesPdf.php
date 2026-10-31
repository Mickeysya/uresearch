<?php

namespace Tests\Feature\Nureen\Concerns;

/**
 * A small structural PDF parser for tests -- composer.json has no PDF
 * parser, and "starts with %PDF" is exactly the check the 45-byte doc.pdf
 * stand-ins passed while no viewer could open them.
 *
 * Follows what a viewer follows: the header, startxref to the
 * cross-reference table, every in-use xref entry to an "N G obj" at exactly
 * that byte offset, the trailer's /Root to a /Catalog, its /Pages to a
 * /Pages node, and that node's /Count. Classic xref tables only, which is
 * what Dompdf writes.
 */
trait ParsesPdf
{
    /** The page count of a structurally valid PDF; fails the test otherwise. */
    protected function pdfPageCount(string $pdf, string $label = 'PDF'): int
    {
        $this->assertMatchesRegularExpression('/\A%PDF-1\.\d/', $pdf, "{$label}: missing %PDF header");
        $this->assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/', $pdf, $m), "{$label}: no startxref/%%EOF trailer");

        $xrefAt = (int) $m[1];
        $this->assertSame('xref', substr($pdf, $xrefAt, 4), "{$label}: startxref does not point at an xref table");

        $offsets = [];
        $lines = preg_split('/\r\n|\r|\n/', substr($pdf, $xrefAt + 4));
        $i = 0;

        while (isset($lines[$i]) && ! str_starts_with(trim($lines[$i]), 'trailer')) {
            $line = trim($lines[$i++]);

            if ($line === '') {
                continue;
            }

            $this->assertSame(1, preg_match('/^(\d+) (\d+)$/', $line, $section), "{$label}: malformed xref subsection '{$line}'");

            for ($n = 0; $n < (int) $section[2]; $n++) {
                $entry = trim($lines[$i++] ?? '');
                $this->assertSame(1, preg_match('/^(\d{10}) (\d{5}) ([nf])$/', $entry, $e), "{$label}: malformed xref entry '{$entry}'");

                if ($e[3] === 'n') {
                    $offsets[(int) $section[1] + $n] = (int) $e[1];
                }
            }
        }

        $this->assertNotEmpty($offsets, "{$label}: xref lists no objects");

        foreach ($offsets as $number => $offset) {
            $this->assertMatchesRegularExpression("/\\A{$number} \\d+ obj/", substr($pdf, $offset, 32), "{$label}: object {$number} is not at its xref offset");
        }

        $trailer = substr($pdf, strpos($pdf, 'trailer', $xrefAt));
        $this->assertSame(1, preg_match('#/Root\s+(\d+)\s+\d+\s+R#', $trailer, $root), "{$label}: trailer has no /Root");

        $catalog = $this->pdfObject($pdf, $offsets, (int) $root[1], $label);
        $this->assertMatchesRegularExpression('#/Type\s*/Catalog#', $catalog, "{$label}: /Root is not a /Catalog");
        $this->assertSame(1, preg_match('#/Pages\s+(\d+)\s+\d+\s+R#', $catalog, $pagesRef), "{$label}: catalog has no /Pages");

        $pages = $this->pdfObject($pdf, $offsets, (int) $pagesRef[1], $label);
        $this->assertMatchesRegularExpression('#/Type\s*/Pages#', $pages, "{$label}: /Pages is not a page tree");
        $this->assertSame(1, preg_match('#/Count\s+(\d+)#', $pages, $count), "{$label}: page tree has no /Count");

        return (int) $count[1];
    }

    private function pdfObject(string $pdf, array $offsets, int $number, string $label): string
    {
        $this->assertArrayHasKey($number, $offsets, "{$label}: object {$number} is not in the xref");

        $start = $offsets[$number];
        $end = strpos($pdf, 'endobj', $start);
        $this->assertNotFalse($end, "{$label}: object {$number} has no endobj");

        return substr($pdf, $start, $end - $start);
    }
}
