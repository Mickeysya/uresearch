<?php

namespace App\Modules\Hani\Support\Pdf;

/**
 * The glyph names a /Differences array commonly uses, mapped to text. Not the
 * whole Adobe Glyph List: single letters and digits, uniXXXX, and the
 * punctuation and ligatures that turn up in thesis and report PDFs.
 */
final class GlyphNames
{
    private const NAMES = [
        'space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$',
        'percent' => '%', 'ampersand' => '&', 'quotesingle' => "'", 'quoteright' => '’', 'quoteleft' => '‘',
        'parenleft' => '(', 'parenright' => ')', 'asterisk' => '*', 'plus' => '+', 'comma' => ',',
        'hyphen' => '-', 'minus' => '-', 'period' => '.', 'slash' => '/', 'colon' => ':', 'semicolon' => ';',
        'less' => '<', 'equal' => '=', 'greater' => '>', 'question' => '?', 'at' => '@',
        'bracketleft' => '[', 'backslash' => '\\', 'bracketright' => ']', 'underscore' => '_',
        'braceleft' => '{', 'bar' => '|', 'braceright' => '}', 'asciitilde' => '~',
        'quotedblleft' => '“', 'quotedblright' => '”', 'endash' => '–', 'emdash' => '—', 'bullet' => '•',
        'ellipsis' => '…', 'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff', 'ffi' => 'ffi', 'ffl' => 'ffl',
        'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
        'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
        'copyright' => '©', 'registered' => '®', 'degree' => '°', 'section' => '§',
    ];

    public static function toUtf8(string $name): ?string
    {
        if (isset(self::NAMES[$name])) {
            return self::NAMES[$name];
        }

        if (preg_match('/^[A-Za-z]$/', $name)) {
            return $name;
        }

        if (preg_match('/^uni([0-9A-Fa-f]{4})$/', $name, $m) || preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m)) {
            return mb_chr(hexdec($m[1]), 'UTF-8') ?: null;
        }

        return null;
    }
}
