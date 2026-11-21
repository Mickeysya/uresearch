<?php

namespace App\Modules\Nureen\Support;

/**
 * The text of a PDF that Dompdf made, read back well enough to search.
 *
 * Not a general PDF parser. Dompdf (CPDF backend) writes each content
 * stream Flate-compressed, and with a Unicode font each string as
 * UTF-16BE in a literal "( ... )" -- so inflate the streams, pull the
 * literals out, undo the PDF escapes and decode. That is all a stored
 * letter needs to answer "which template made this?".
 *
 * Whitespace is dropped from the result: a line break can fall anywhere in
 * a wrapped paragraph, so callers match against whitespace-free markers.
 */
class PdfText
{
    public static function compact(string $pdf): string
    {
        $text = '';

        foreach (self::streams($pdf) as $stream) {
            $content = @gzuncompress($stream);

            if ($content === false) {
                continue;
            }

            preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $content, $literals);

            foreach ($literals[0] as $literal) {
                $text .= self::decode(self::unescape(substr($literal, 1, -1)));
            }
        }

        // Bytes, not /u: a literal that was not text (or not valid UTF-8)
        // must not blank the whole result.
        return str_replace([' ', "\t", "\n", "\r", "\f", "\u{00A0}"], '', $text);
    }

    /**
     * The raw bytes between each "stream" and "endstream", found with strpos
     * rather than a regex: an embedded font stream is hundreds of KB, more
     * than PCRE's backtrack limit allows a lazy match to cross.
     *
     * @return list<string>
     */
    protected static function streams(string $pdf): array
    {
        $streams = [];
        $offset = 0;

        while (($start = strpos($pdf, 'stream', $offset)) !== false) {
            $body = $start + 6;
            // "endstream" also contains "stream"; skip it.
            if ($start >= 3 && substr($pdf, $start - 3, 3) === 'end') {
                $offset = $body;

                continue;
            }

            $body += substr($pdf, $body, 2) === "\r\n" ? 2 : (($pdf[$body] ?? '') === "\n" ? 1 : 0);
            $end = strpos($pdf, 'endstream', $body);

            if ($end === false) {
                break;
            }

            // Drop the one end-of-line before "endstream", not data bytes.
            $raw = substr($pdf, $body, $end - $body);
            $streams[] = str_ends_with($raw, "\r\n") ? substr($raw, 0, -2)
                : (str_ends_with($raw, "\n") || str_ends_with($raw, "\r") ? substr($raw, 0, -1) : $raw);
            $offset = $end + 9;
        }

        return $streams;
    }

    protected static function unescape(string $literal): string
    {
        return (string) preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', function (array $m) {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                '(', ')', '\\' => $m[1],
                default => chr(octdec($m[1]) & 0xFF),
            };
        }, $literal);
    }

    /** UTF-16BE (what Dompdf writes for a Unicode font), else as-is. */
    protected static function decode(string $bytes): string
    {
        if ($bytes !== '' && strlen($bytes) % 2 === 0 && $bytes[0] === "\x00") {
            return (string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }

        return $bytes;
    }
}
