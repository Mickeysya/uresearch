<?php

namespace App\Modules\Hani\Support\Pdf;

/**
 * The one door to reading an uploaded PDF.
 *
 * facts() never needs a parser: magic bytes, the encryption marker and the
 * page count come from the raw file. text() uses smalot/pdfparser if it is
 * ever installed (proposed, optional: SHARED_CHANGES.md) and the built-in
 * BasicPdfText otherwise. Either way, unreadable text is null, never a guess.
 */
class PdfReader
{
    /** Which engine text() is using, for the "System checks" panel. */
    public function engine(): string
    {
        return class_exists(\Smalot\PdfParser\Parser::class) ? 'smalot/pdfparser' : 'built-in reader';
    }

    /**
     * @return array{is_pdf: bool, encrypted: bool, pages: ?int}
     */
    public function facts(string $bytes): array
    {
        // The header may follow a few junk bytes; the spec allows 1024.
        $isPdf = str_contains(substr($bytes, 0, 1024), '%PDF-');

        if (! $isPdf) {
            return ['is_pdf' => false, 'encrypted' => false, 'pages' => null];
        }

        $encrypted = (bool) preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $bytes)
            || (bool) preg_match('/\/Encrypt\s*<</', $bytes);

        $pages = $encrypted ? null : (new BasicPdfText($bytes))->pageCount();
        $pages ??= preg_match_all('/\/Type\s*\/Page(?![A-Za-z])/', $bytes) ?: null;

        return ['is_pdf' => true, 'encrypted' => $encrypted, 'pages' => $pages];
    }

    /**
     * Text per page, or null when it cannot be read.
     *
     * @return array<int, string>|null
     */
    public function text(string $bytes, ?int $maxPages = null): ?array
    {
        $facts = $this->facts($bytes);

        if (! $facts['is_pdf'] || $facts['encrypted']) {
            return null;
        }

        try {
            if (class_exists(\Smalot\PdfParser\Parser::class)) {
                $pages = array_map(
                    fn ($page) => (string) $page->getText(),
                    (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getPages(),
                );
                $pages = $maxPages ? array_slice($pages, 0, $maxPages) : $pages;

                return BasicPdfText::readable($pages) ? $pages : null;
            }

            return (new BasicPdfText($bytes))->text($maxPages);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
