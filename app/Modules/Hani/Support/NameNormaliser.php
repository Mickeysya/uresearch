<?php

namespace App\Modules\Hani\Support;

/**
 * Reduces a person's name to the words that identify them, and says how alike
 * two names are.
 *
 * CGS's sheets write the same examiner several ways -- "Prof Dr Hassan bin
 * Omar (UTM)", "Hassan Omar, PhD", "AP Dr ..." -- and a co-author list says
 * "Nurul Aina Aziz" for "Assoc Prof Dr Nurul Aina binti Aziz". Titles,
 * patronymic connectors (bin/binti, a/l, a/p), bracketed institution hints,
 * punctuation and spacing are all dropped first; what is left is compared with
 * levenshtein() and similar_text(), over the words in their given order and
 * sorted (so "Omar Hassan" still matches "Hassan Omar").
 *
 * Used by the pool import (matching a row without an email), duplicate
 * detection, and the co-authorship conflict-of-interest check. Plain PHP, no
 * external service, and the score it returns is shown to the user with both
 * names, so a flag always says what it compared.
 */
class NameNormaliser
{
    /** "hassan omar": lower case, titles and connectors gone. */
    public function normalise(?string $name): string
    {
        $name = mb_strtolower((string) $name);

        // "(UTM)" and similar: an institution hint, not part of the name.
        $name = preg_replace('/\([^)]*\)/u', ' ', $name);

        // a/l, a/p, s/o, d/o before the slash is lost to punctuation.
        $name = preg_replace('/\b([ad])\s*\/\s*([lp])\b|\b([sd])\s*\/\s*o\b/u', ' ', $name);

        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);
        $name = ' '.trim(preg_replace('/\s+/u', ' ', $name)).' ';

        // Multi-word titles first ("tan sri" before a lone word could match).
        $titles = config('hani.names.titles', []);
        usort($titles, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($titles as $title) {
            $name = str_replace(' '.$title.' ', ' ', $name);
            // A title can repeat ("Prof Dr Ir"), and removal can expose another.
            $name = str_replace(' '.$title.' ', ' ', $name);
        }

        $connectors = config('hani.names.connectors', []);

        $words = array_filter(
            explode(' ', trim($name)),
            fn ($word) => $word !== '' && mb_strlen($word) > 1 && ! in_array($word, $connectors, true)
        );

        return implode(' ', $words);
    }

    /**
     * How alike two names are, 0-100, after normalising both. The higher of
     * a character-level score on the words as written and on the words
     * sorted; 100 when the normalised names are identical.
     */
    public function similarity(?string $a, ?string $b): int
    {
        $a = $this->normalise($a);
        $b = $this->normalise($b);

        if ($a === '' || $b === '') {
            return 0;
        }

        if ($a === $b) {
            return 100;
        }

        $sortedA = $this->sorted($a);
        $sortedB = $this->sorted($b);

        return (int) round(max(
            $this->score($a, $b),
            $this->score($sortedA, $sortedB),
        ));
    }

    /** True at or above the configured threshold (config hani.duplicates.threshold). */
    public function looksAlike(?string $a, ?string $b): bool
    {
        return $this->similarity($a, $b) >= (int) config('hani.duplicates.threshold', 85);
    }

    protected function sorted(string $normalised): string
    {
        $words = explode(' ', $normalised);
        sort($words);

        return implode(' ', $words);
    }

    /** Average of levenshtein-based and similar_text-based similarity, 0-100. */
    protected function score(string $a, string $b): float
    {
        $longest = max(strlen($a), strlen($b));
        $lev = $longest > 255
            ? 0.0
            : (1 - levenshtein($a, $b) / $longest) * 100;

        similar_text($a, $b, $percent);

        return ($lev + $percent) / 2;
    }
}
