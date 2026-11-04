<?php

namespace App\Modules\Hani\Support\Pdf;

/**
 * A small PDF text reader in plain PHP, used when smalot/pdfparser is not
 * installed (it is not, today; see SHARED_CHANGES.md).
 *
 * It handles the common cases and deliberately no more:
 *   - objects, including those packed into object streams (PDF 1.5+)
 *   - FlateDecode and ASCIIHex streams
 *   - simple fonts in WinAnsi / MacRoman / Standard encoding, with
 *     /Differences for the usual glyph names
 *   - Type0 / CID fonts that carry a ToUnicode map (bfchar and bfrange)
 *   - the page tree, in reading order, with inherited resources
 *   - Tj, TJ, ' and " text, with line breaks from Td / TD / T* / Tm
 *
 * It does not decrypt, OCR scanned pages, or decode CID fonts without a
 * ToUnicode map. Anything it cannot read comes back as null, and the caller
 * shows "Not checked automatically" instead of guessing.
 */
final class BasicPdfText
{
    /** @var array<int, array{dict: mixed, stream: ?string}> */
    private array $objects = [];

    /** @var array<int, ?string> decoded streams, by object number */
    private array $decoded = [];

    /** @var array<string, array> font decoders, by object key */
    private array $fonts = [];

    private ?array $pageList = null;

    public function __construct(private readonly string $data)
    {
        $this->readObjects();
    }

    /** Pages found in the page tree, or null if there is no page tree. */
    public function pageCount(): ?int
    {
        $pages = $this->pages();

        return $pages === [] ? null : count($pages);
    }

    /**
     * The text of each page, in order. Null when nothing readable came out:
     * a scanned thesis, an unsupported font, or not a PDF at all.
     *
     * @param  int|null  $maxPages  stop after this many (the title check needs two)
     * @return array<int, string>|null
     */
    public function text(?int $maxPages = null): ?array
    {
        $out = [];

        foreach ($this->pages() as $i => $page) {
            if ($maxPages !== null && $i >= $maxPages) {
                break;
            }

            $out[] = $this->pageText($page);
        }

        return self::readable($out) ? $out : null;
    }

    /** Enough real letters, and few enough junk characters, to count as text. */
    public static function readable(array $pages): bool
    {
        $text = implode("\n", $pages);
        $letters = preg_match_all('/\p{L}/u', $text);
        $junk = preg_match_all('/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{FFFD}]/u', $text);

        return $letters >= 20 && $junk <= $letters * 0.1;
    }

    // ---- Objects -----------------------------------------------------------

    private function readObjects(): void
    {
        if (! preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->data, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $number = (int) $matches[1][$i][0];
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $limit = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($this->data);

            $body = substr($this->data, $start, $limit - $start);
            $stream = null;

            if (preg_match('/\bstream\r?\n/', $body, $m, PREG_OFFSET_CAPTURE)) {
                $streamStart = $m[0][1] + strlen($m[0][0]);
                $streamEnd = strrpos($body, 'endstream');
                $stream = substr($body, $streamStart, ($streamEnd === false ? strlen($body) : $streamEnd) - $streamStart);
                $body = substr($body, 0, $m[0][1]);
            } else {
                $end = strpos($body, 'endobj');
                $body = $end === false ? $body : substr($body, 0, $end);
            }

            // Later definitions win: that is how incremental updates work.
            $this->objects[$number] = ['dict' => (new PdfValueParser($body))->value(), 'stream' => $stream];
        }

        foreach ($this->objects as $number => $object) {
            if ($this->name($this->get($object['dict'], 'Type')) === 'ObjStm') {
                $this->unpackObjectStream($number);
            }
        }
    }

    private function unpackObjectStream(int $number): void
    {
        $data = $this->stream($number);
        $dict = $this->objects[$number]['dict'];
        $n = (int) $this->resolve($this->get($dict, 'N'));
        $first = (int) $this->resolve($this->get($dict, 'First'));

        if ($data === null || $n < 1) {
            return;
        }

        $header = preg_split('/\s+/', trim(substr($data, 0, $first)));
        $entries = [];

        for ($i = 0; $i + 1 < count($header) && count($entries) < $n; $i += 2) {
            $entries[] = [(int) $header[$i], (int) $header[$i + 1]];
        }

        foreach ($entries as $k => [$objectNumber, $offset]) {
            $end = isset($entries[$k + 1]) ? $entries[$k + 1][1] : strlen($data) - $first;

            // An object written outside the stream (a later update) wins.
            $this->objects[$objectNumber] ??= [
                'dict' => (new PdfValueParser(substr($data, $first + $offset, $end - $offset)))->value(),
                'stream' => null,
            ];
        }
    }

    /** A decoded stream, or null if its filter is not one we read. */
    private function stream(int $number): ?string
    {
        if (array_key_exists($number, $this->decoded)) {
            return $this->decoded[$number];
        }

        $object = $this->objects[$number] ?? null;
        $data = $object['stream'] ?? null;

        if ($data === null) {
            return $this->decoded[$number] = null;
        }

        $length = $this->resolve($this->get($object['dict'], 'Length'));
        if (is_int($length) && $length > 0 && $length <= strlen($data)) {
            $data = substr($data, 0, $length);
        }

        $filters = $this->resolve($this->get($object['dict'], 'Filter'));
        $filters = match (true) {
            $filters === null => [],
            is_array($filters) && ($filters['t'] ?? null) === 'array' => $filters['v'],
            default => [$filters],
        };

        foreach ($filters as $filter) {
            $data = match ($this->name($this->resolve($filter))) {
                'FlateDecode', 'Fl' => self::inflate($data),
                'ASCIIHexDecode', 'AHx' => self::hex(rtrim(trim($data), '>')),
                default => null,
            };

            if ($data === null) {
                break;
            }
        }

        return $this->decoded[$number] = $data;
    }

    private static function inflate(string $data): ?string
    {
        foreach ([$data, rtrim($data, "\r\n ")] as $candidate) {
            $out = @gzuncompress($candidate);
            if ($out !== false) {
                return $out;
            }

            // A zlib stream with a damaged header or checksum: try the raw
            // deflate data inside it.
            $out = @gzinflate(substr($candidate, 2));
            if ($out !== false) {
                return $out;
            }
        }

        return null;
    }

    private static function hex(string $hex): string
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);

        return (string) hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex);
    }

    // ---- Values ------------------------------------------------------------

    private function resolve(mixed $value, int $depth = 0): mixed
    {
        while (is_array($value) && ($value['t'] ?? null) === 'ref' && $depth++ < 32) {
            $value = $this->objects[$value['v']]['dict'] ?? null;
        }

        return $value;
    }

    private function get(mixed $dict, string $key): mixed
    {
        $dict = $this->resolve($dict);

        return is_array($dict) && ($dict['t'] ?? null) === 'dict' ? ($dict['v'][$key] ?? null) : null;
    }

    private function name(mixed $value): ?string
    {
        return is_array($value) && ($value['t'] ?? null) === 'name' ? $value['v'] : null;
    }

    private function list(mixed $value): array
    {
        $value = $this->resolve($value);

        return is_array($value) && ($value['t'] ?? null) === 'array' ? $value['v'] : ($value === null ? [] : [$value]);
    }

    // ---- Pages -------------------------------------------------------------

    /** @return array<int, array{contents: array, resources: mixed}> */
    private function pages(): array
    {
        if ($this->pageList !== null) {
            return $this->pageList;
        }

        $this->pageList = [];
        $roots = [];

        foreach ($this->objects as $number => $object) {
            if ($this->name($this->get($object['dict'], 'Type')) === 'Pages' && $this->get($object['dict'], 'Parent') === null) {
                $roots[] = $number;
            }
        }

        $seen = [];
        foreach ($roots as $root) {
            $this->walk(['t' => 'ref', 'v' => $root], null, $seen);
        }

        // No usable tree: every Page object, in object order.
        if ($this->pageList === []) {
            foreach ($this->objects as $object) {
                if ($this->name($this->get($object['dict'], 'Type')) === 'Page') {
                    $this->pageList[] = ['contents' => $this->get($object['dict'], 'Contents'), 'resources' => $this->get($object['dict'], 'Resources')];
                }
            }
        }

        return $this->pageList;
    }

    private function walk(mixed $node, mixed $inherited, array &$seen): void
    {
        if (is_array($node) && ($node['t'] ?? null) === 'ref') {
            if (isset($seen[$node['v']])) {
                return;
            }
            $seen[$node['v']] = true;
        }

        $resources = $this->get($node, 'Resources') ?? $inherited;

        if ($this->name($this->get($node, 'Type')) === 'Page') {
            $this->pageList[] = ['contents' => $this->get($node, 'Contents'), 'resources' => $resources];

            return;
        }

        foreach ($this->list($this->get($node, 'Kids')) as $kid) {
            $this->walk($kid, $resources, $seen);
        }
    }

    private function pageText(array $page): string
    {
        $content = '';

        // /Contents is one stream reference, or an array (perhaps itself
        // indirect) of them. Keep the references: the stream hangs off them.
        $contents = $page['contents'];
        $resolved = $this->resolve($contents);
        $refs = is_array($resolved) && ($resolved['t'] ?? null) === 'array' ? $resolved['v'] : [$contents];

        foreach ($refs as $ref) {
            if (is_array($ref) && ($ref['t'] ?? null) === 'ref') {
                $content .= ($this->stream($ref['v']) ?? '')."\n";
            }
        }

        $fonts = [];
        $fontDict = $this->resolve($this->get($page['resources'], 'Font'));

        if (is_array($fontDict) && ($fontDict['t'] ?? null) === 'dict') {
            foreach ($fontDict['v'] as $name => $font) {
                $fonts[$name] = $this->font($font);
            }
        }

        return (new PdfContentText($content, $fonts))->text();
    }

    // ---- Fonts -------------------------------------------------------------

    /**
     * How to turn this font's string bytes into text:
     * ['width' => 1|2, 'map' => [code => utf8], 'fallback' => ?encoding]
     */
    private function font(mixed $font): array
    {
        $key = is_array($font) && ($font['t'] ?? null) === 'ref' ? 'ref'.$font['v'] : md5(serialize($font));

        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }

        $subtype = $this->name($this->get($font, 'Subtype'));
        $decoder = ['width' => $subtype === 'Type0' ? 2 : 1, 'map' => [], 'fallback' => $subtype === 'Type0' ? null : 'Windows-1252'];

        $encoding = $this->resolve($this->get($font, 'Encoding'));
        if ($this->name($encoding) === 'MacRomanEncoding') {
            $decoder['fallback'] = 'MacRoman';
        } elseif (is_array($encoding) && ($encoding['t'] ?? null) === 'dict') {
            if ($this->name($this->get($encoding, 'BaseEncoding')) === 'MacRomanEncoding') {
                $decoder['fallback'] = 'MacRoman';
            }
            $decoder['map'] = $this->differences($this->list($this->get($encoding, 'Differences')));
        }

        $toUnicode = $this->get($font, 'ToUnicode');
        if (is_array($toUnicode) && ($toUnicode['t'] ?? null) === 'ref' && ($cmap = $this->stream($toUnicode['v'])) !== null) {
            [$width, $map] = self::cmap($cmap);
            $decoder['width'] = $width ?? $decoder['width'];
            $decoder['map'] = $map + $decoder['map'];
        }

        return $this->fonts[$key] = $decoder;
    }

    /** /Differences [code /name /name code /name ...] -> [code => utf8]. */
    private function differences(array $items): array
    {
        $map = [];
        $code = 0;

        foreach ($items as $item) {
            $item = $this->resolve($item);

            if (is_int($item) || is_float($item)) {
                $code = (int) $item;
            } elseif (($name = $this->name($item)) !== null) {
                if (($char = GlyphNames::toUtf8($name)) !== null) {
                    $map[$code] = $char;
                }
                $code++;
            }
        }

        return $map;
    }

    /**
     * A ToUnicode CMap: its code width and its bfchar / bfrange entries.
     *
     * @return array{0: ?int, 1: array<int, string>}
     */
    public static function cmap(string $cmap): array
    {
        $width = null;
        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m)) {
            $width = max(1, intdiv(strlen($m[1]), 2));
        }

        $map = [];
        $utf16 = fn (string $hex) => mb_convert_encoding(self::hex($hex), 'UTF-8', 'UTF-16BE');

        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER);
                foreach ($pairs as [, $src, $dst]) {
                    $map[hexdec($src)] = $utf16($dst);
                }
            }
        }

        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER);
                foreach ($ranges as [, $lo, $hi, $dst]) {
                    $lo = hexdec($lo);
                    $hi = min(hexdec($hi), $lo + 65535);

                    if ($dst[0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $dst, $each);
                        foreach ($each[1] as $i => $hex) {
                            $map[$lo + $i] = $utf16($hex);
                        }

                        continue;
                    }

                    // <lo> <hi> <dst>: the last byte of dst counts up with the code.
                    $base = self::hex(trim($dst, '<>'));
                    for ($code = $lo; $code <= $hi; $code++) {
                        $bytes = $base;
                        $offset = $code - $lo;
                        $last = strlen($bytes) - 1;
                        if ($last >= 1) {
                            $value = (ord($bytes[$last - 1]) << 8 | ord($bytes[$last])) + $offset;
                            $bytes = substr($bytes, 0, $last - 1).chr(($value >> 8) & 0xFF).chr($value & 0xFF);
                        }
                        $map[$code] = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
                    }
                }
            }
        }

        return [$width, $map];
    }
}
