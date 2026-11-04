<?php

namespace App\Modules\Hani\Support\Pdf;

/**
 * Turns one page's content stream into text, for BasicPdfText.
 *
 * Reads the text-showing operators (Tj, TJ, ', ") through the current
 * font's decoder, and inserts line breaks and spaces from the positioning
 * operators: a new line when the vertical position changes, a space when a
 * run starts further along the same line or a TJ gap is wide enough.
 */
final class PdfContentText
{
    private string $out = '';

    private ?array $font = null;

    /** Current text line position (y), relative to the start of the text object. */
    private float $lineY = 0.0;

    private ?float $lastY = null;

    private bool $space = false;

    /**
     * @param  array<string, array>  $fonts  resource name => decoder (see BasicPdfText::font())
     */
    public function __construct(private readonly string $content, private readonly array $fonts) {}

    public function text(): string
    {
        $src = $this->content;
        $len = strlen($src);
        $pos = 0;
        $operands = [];

        while ($pos < $len) {
            $c = $src[$pos];

            if (ctype_space($c) || $c === "\0") {
                $pos++;

                continue;
            }

            if ($c === '%') {
                $pos += strcspn($src, "\r\n", $pos);

                continue;
            }

            if ($c === '(') {
                $operands[] = ['s', PdfValueParser::literal($src, $pos)];

                continue;
            }

            if ($c === '<' && ($src[$pos + 1] ?? '') === '<') {
                // An inline dictionary (marked content): skip it whole.
                $depth = 0;
                while ($pos < $len) {
                    if (substr($src, $pos, 2) === '<<') {
                        $depth++;
                        $pos += 2;
                    } elseif (substr($src, $pos, 2) === '>>') {
                        $depth--;
                        $pos += 2;
                        if ($depth === 0) {
                            break;
                        }
                    } elseif ($src[$pos] === '(') {
                        PdfValueParser::literal($src, $pos);
                    } else {
                        $pos++;
                    }
                }
                $operands[] = ['d', null];

                continue;
            }

            if ($c === '<') {
                $end = strpos($src, '>', $pos);
                $end = $end === false ? $len : $end;
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($src, $pos + 1, $end - $pos - 1));
                $operands[] = ['s', (string) hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex)];
                $pos = $end + 1;

                continue;
            }

            if ($c === '[') {
                $operands[] = ['a', $this->array($src, $pos)];

                continue;
            }

            if ($c === '/') {
                $start = ++$pos;
                while ($pos < $len && ! PdfValueParser::isDelimiter($src[$pos])) {
                    $pos++;
                }
                $operands[] = ['n', substr($src, $start, $pos - $start)];

                continue;
            }

            if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $src, $m, 0, $pos)) {
                $operands[] = ['f', (float) $m[0]];
                $pos += strlen($m[0]);

                continue;
            }

            // An operator.
            $start = $pos;
            while ($pos < $len && ! PdfValueParser::isDelimiter($src[$pos])) {
                $pos++;
            }
            if ($pos === $start) {
                $pos++;
                $operands = [];

                continue;
            }

            $operator = substr($src, $start, $pos - $start);

            if ($operator === 'BI') {
                // Inline image: skip to its EI.
                $ei = strpos($src, 'EI', $pos);
                $pos = $ei === false ? $len : $ei + 2;
            } else {
                $this->operate($operator, $operands);
            }

            $operands = [];
        }

        // Tidy: no trailing spaces, at most one blank line in a row.
        $text = preg_replace('/[ \t]+\n/', "\n", $this->out);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** A TJ array: strings and numeric gaps. */
    private function array(string $src, int &$pos): array
    {
        $len = strlen($src);
        $pos++;
        $items = [];

        while ($pos < $len && $src[$pos] !== ']') {
            $c = $src[$pos];

            if ($c === '(') {
                $items[] = PdfValueParser::literal($src, $pos);
            } elseif ($c === '<') {
                $end = strpos($src, '>', $pos);
                $end = $end === false ? $len : $end;
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($src, $pos + 1, $end - $pos - 1));
                $items[] = (string) hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex);
                $pos = $end + 1;
            } elseif (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $src, $m, 0, $pos)) {
                $items[] = (float) $m[0];
                $pos += strlen($m[0]);
            } else {
                $pos++;
            }
        }

        $pos++;

        return $items;
    }

    private function operate(string $operator, array $operands): void
    {
        $num = fn (int $i) => (float) ($operands[$i][1] ?? 0);

        switch ($operator) {
            case 'BT':
                $this->lineY = 0.0;
                break;

            case 'Tf':
                $name = $operands[0][1] ?? null;
                $this->font = is_string($name) ? ($this->fonts[$name] ?? null) : null;
                break;

            case 'Td':
            case 'TD':
                $this->moveTo($this->lineY + $num(1), $num(0) > 0);
                break;

            case 'Tm':
                $this->moveTo($num(5), true);
                break;

            case 'T*':
                $this->newLine();
                break;

            case 'Tj':
                $this->show($operands[0][1] ?? '');
                break;

            case "'":
                $this->newLine();
                $this->show($operands[0][1] ?? '');
                break;

            case '"':
                $this->newLine();
                $this->show($operands[2][1] ?? '');
                break;

            case 'TJ':
                foreach (($operands[0][1] ?? []) as $item) {
                    if (is_string($item)) {
                        $this->show($item);
                    } elseif ($item < -200) {
                        // A gap of more than a fifth of an em reads as a word break.
                        $this->space = true;
                    }
                }
                break;
        }
    }

    private function moveTo(float $y, bool $alongTheLine): void
    {
        if ($this->lastY !== null && abs($y - $this->lastY) > 1.0) {
            $this->newLine();
        } elseif ($alongTheLine) {
            $this->space = true;
        }

        $this->lineY = $y;
    }

    private function newLine(): void
    {
        if ($this->out !== '' && ! str_ends_with($this->out, "\n")) {
            $this->out .= "\n";
        }
        $this->space = false;
    }

    private function show(mixed $bytes): void
    {
        if (! is_string($bytes) || $bytes === '') {
            return;
        }

        $text = $this->decode($bytes);

        if ($text === '') {
            return;
        }

        if ($this->space && $this->out !== '' && ! preg_match('/\s$/u', $this->out) && ! preg_match('/^\s/u', $text)) {
            $this->out .= ' ';
        }

        $this->out .= $text;
        $this->space = false;
        $this->lastY = $this->lineY;
    }

    private function decode(string $bytes): string
    {
        $font = $this->font ?? ['width' => 1, 'map' => [], 'fallback' => 'Windows-1252'];
        $width = $font['width'];
        $out = '';

        for ($i = 0, $n = strlen($bytes); $i < $n; $i += $width) {
            $code = $width === 2
                ? (ord($bytes[$i]) << 8) | ord($bytes[$i + 1] ?? "\0")
                : ord($bytes[$i]);

            if (isset($font['map'][$code])) {
                $out .= $font['map'][$code];
            } elseif ($font['fallback'] !== null && $width === 1) {
                $out .= self::single(chr($code), $font['fallback']);
            } else {
                // A CID with no ToUnicode entry: unreadable. BasicPdfText::readable()
                // counts these and gives up on the page if there are many.
                $out .= "\u{FFFD}";
            }
        }

        return $out;
    }

    private static function single(string $byte, string $encoding): string
    {
        if ($byte < ' ' && ! in_array($byte, ["\t", "\n", "\r"], true)) {
            return '';
        }

        if ($encoding === 'MacRoman') {
            return ord($byte) < 128 ? $byte : (string) @iconv('MACINTOSH', 'UTF-8//IGNORE', $byte);
        }

        return mb_convert_encoding($byte, 'UTF-8', 'Windows-1252');
    }
}
