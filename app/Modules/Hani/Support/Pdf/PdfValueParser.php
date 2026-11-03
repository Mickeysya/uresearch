<?php

namespace App\Modules\Hani\Support\Pdf;

/**
 * Parses one PDF object's value: dictionaries, arrays, names, numbers,
 * strings, booleans, null and indirect references. Used by BasicPdfText.
 *
 * Values come back as plain arrays so they serialise and compare cheaply:
 *   dict  ['t' => 'dict',  'v' => [key => value]]   (keys without the slash)
 *   array ['t' => 'array', 'v' => [value, ...]]
 *   ref   ['t' => 'ref',   'v' => object number]
 *   name  ['t' => 'name',  'v' => 'FlateDecode']
 *   str   ['t' => 'str',   'v' => raw bytes]
 *   numbers as int / float, true / false, null
 */
final class PdfValueParser
{
    private int $pos = 0;

    private int $len;

    public function __construct(private readonly string $src)
    {
        $this->len = strlen($src);
    }

    public function value(int $depth = 0): mixed
    {
        $this->skip();

        if ($this->pos >= $this->len || $depth > 64) {
            return null;
        }

        $c = $this->src[$this->pos];

        if ($c === '<' && ($this->src[$this->pos + 1] ?? '') === '<') {
            $this->pos += 2;
            $dict = [];

            while (true) {
                $this->skip();
                if ($this->pos >= $this->len) {
                    break;
                }
                if ($this->src[$this->pos] === '>' && ($this->src[$this->pos + 1] ?? '') === '>') {
                    $this->pos += 2;
                    break;
                }
                $key = $this->value($depth + 1);
                if (! is_array($key) || $key['t'] !== 'name') {
                    // Not a key: skip a character so a damaged dictionary cannot loop.
                    $this->pos++;

                    continue;
                }
                $dict[$key['v']] = $this->value($depth + 1);
            }

            return ['t' => 'dict', 'v' => $dict];
        }

        if ($c === '[') {
            $this->pos++;
            $items = [];

            while (true) {
                $this->skip();
                if ($this->pos >= $this->len) {
                    break;
                }
                if ($this->src[$this->pos] === ']') {
                    $this->pos++;
                    break;
                }
                $before = $this->pos;
                $items[] = $this->value($depth + 1);
                if ($this->pos === $before) {
                    $this->pos++;
                }
            }

            return ['t' => 'array', 'v' => $items];
        }

        if ($c === '/') {
            $this->pos++;
            $start = $this->pos;
            while ($this->pos < $this->len && ! self::isDelimiter($this->src[$this->pos])) {
                $this->pos++;
            }

            // #xx escapes in names.
            $name = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), substr($this->src, $start, $this->pos - $start));

            return ['t' => 'name', 'v' => $name];
        }

        if ($c === '(') {
            return ['t' => 'str', 'v' => self::literal($this->src, $this->pos)];
        }

        if ($c === '<') {
            $end = strpos($this->src, '>', $this->pos);
            $end = $end === false ? $this->len : $end;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($this->src, $this->pos + 1, $end - $this->pos - 1));
            $this->pos = $end + 1;

            return ['t' => 'str', 'v' => (string) hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex)];
        }

        // A number, possibly the start of "n g R".
        if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);

            if (ctype_digit($m[0]) && preg_match('/\G\s+(\d+)\s+R(?![A-Za-z])/', $this->src, $r, 0, $this->pos)) {
                $this->pos += strlen($r[0]);

                return ['t' => 'ref', 'v' => (int) $m[0]];
            }

            return str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0];
        }

        if (preg_match('/\G(true|false|null)/', $this->src, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);

            return match ($m[0]) { 'true' => true, 'false' => false, default => null };
        }

        $this->pos++;

        return null;
    }

    private function skip(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];

            if ($c === '%') {
                $end = strcspn($this->src, "\r\n", $this->pos);
                $this->pos += $end;
            } elseif (ctype_space($c) || $c === "\0") {
                $this->pos++;
            } else {
                break;
            }
        }
    }

    public static function isDelimiter(string $c): bool
    {
        return ctype_space($c) || str_contains('()<>[]{}/%', $c) || $c === "\0";
    }

    /**
     * A literal string starting at $pos (on the opening bracket), with its
     * escapes and nested brackets. Moves $pos past the closing bracket.
     */
    public static function literal(string $src, int &$pos): string
    {
        $len = strlen($src);
        $pos++;
        $depth = 1;
        $out = '';

        while ($pos < $len) {
            $c = $src[$pos++];

            if ($c === '\\') {
                $n = $src[$pos++] ?? '';
                switch ($n) {
                    case 'n': $out .= "\n"; break;
                    case 'r': $out .= "\r"; break;
                    case 't': $out .= "\t"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\x0C"; break;
                    case "\r":
                        if (($src[$pos] ?? '') === "\n") {
                            $pos++;
                        }
                        break;
                    case "\n": break;
                    default:
                        if ($n !== '' && $n >= '0' && $n <= '7') {
                            $oct = $n;
                            while (strlen($oct) < 3 && ($src[$pos] ?? '') >= '0' && ($src[$pos] ?? '') <= '7') {
                                $oct .= $src[$pos++];
                            }
                            $out .= chr(octdec($oct) & 0xFF);
                        } else {
                            $out .= $n;
                        }
                }

                continue;
            }

            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                break;
            }

            $out .= $c;
        }

        return $out;
    }
}
