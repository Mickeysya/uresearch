<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Support\StudentDirectory;

/**
 * PART 4: runs before ANY text reaches a model call -- chooseTool() and
 * composeGuidance() never see a real 8-digit student ID, email, or name.
 * mask() handles IDs/emails (reversible, via the returned map); name
 * detection is a hard stop instead, since there is no safe way to mask a
 * name out of free text without knowing where it starts and ends.
 */
final class PrivacyMasker
{
    /**
     * Emails first -- an email's local part can itself contain 8 digits
     * that the ID pattern below would otherwise also catch.
     *
     * @return array{masked: string, idMap: array<string, string>}
     */
    public static function mask(string $text): array
    {
        $idMap = [];
        $emailCounter = 0;
        $idCounter = 0;

        $masked = preg_replace_callback(
            '/[^\s@]+@[^\s@]+\.[^\s@]+/',
            function (array $m) use (&$idMap, &$emailCounter) {
                $emailCounter++;
                $placeholder = "<EMAIL{$emailCounter}>";
                $idMap[$placeholder] = $m[0];

                return $placeholder;
            },
            $text
        );

        $masked = preg_replace_callback(
            '/\b\d{8}\b/',
            function (array $m) use (&$idMap, &$idCounter) {
                $idCounter++;
                $placeholder = "<ID{$idCounter}>";
                $idMap[$placeholder] = $m[0];

                return $placeholder;
            },
            $masked
        );

        return ['masked' => $masked, 'idMap' => $idMap];
    }

    /** Maps a placeholder the model echoed back in a tool parameter to the real value -- null if it isn't one of this question's own placeholders. */
    public static function unmask(string $value, array $idMap): string
    {
        return $idMap[$value] ?? $value;
    }

    public static function isEmailPlaceholder(string $value): bool
    {
        return (bool) preg_match('/^<EMAIL\d+>$/', $value);
    }

    /**
     * True if $text contains a known student's full name, or two
     * consecutive words of one -- real accounts and demo profiles both,
     * via StudentDirectory. Punctuation is stripped before matching so
     * "Ahmad Danial?" still matches "Ahmad Danial". A single-word name is
     * never checked on its own -- too ambiguous to mask reliably and too
     * likely to false-positive on an ordinary word.
     */
    public static function containsKnownStudentName(string $text): bool
    {
        $normalized = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $normalized = ' '.trim(preg_replace('/\s+/', ' ', $normalized)).' ';

        if (trim($normalized) === '') {
            return false;
        }

        foreach (StudentDirectory::all() as $identity) {
            $words = array_values(array_filter(preg_split('/\s+/', trim($identity->name))));

            if (count($words) < 2) {
                continue;
            }

            $fullName = ' '.mb_strtolower(implode(' ', $words)).' ';

            if (str_contains($normalized, $fullName)) {
                return true;
            }

            for ($i = 0; $i < count($words) - 1; $i++) {
                $bigram = ' '.mb_strtolower($words[$i].' '.$words[$i + 1]).' ';

                if (mb_strlen(trim($bigram)) > 3 && str_contains($normalized, $bigram)) {
                    return true;
                }
            }
        }

        return false;
    }
}
