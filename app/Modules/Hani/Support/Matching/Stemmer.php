<?php

namespace App\Modules\Hani\Support\Matching;

/**
 * A deliberately light English suffix-stripper.
 *
 * Enough that "detection"/"detecting"/"detect", "networks"/"network" and
 * "materials"/"material" land on the same term; not a full Porter stemmer,
 * which over-conflates short technical words ("process"/"processing" is
 * wanted, "organ"/"organisation" is not). Words of four letters or fewer
 * are left alone, and nothing is cut below a three-letter stem.
 */
class Stemmer
{
    /** Longest first: the first suffix that fits wins. */
    protected const SUFFIXES = [
        'isations' => 'is', 'izations' => 'iz', 'isation' => 'is', 'ization' => 'iz',
        'ational' => 'at', 'ations' => 'at', 'ation' => 'at',
        'nesses' => '', 'ness' => '',
        'ments' => '', 'ment' => '',
        'ities' => '', 'ity' => '',
        'ings' => '', 'ing' => '',
        'ions' => '', 'ion' => '',
        'ies' => 'y', 'ied' => 'y',
        // Only the plurals that really add "es"; a bare "es" rule would
        // split "pipelines" from "pipeline".
        'sses' => 'ss', 'shes' => 'sh', 'ches' => 'ch', 'xes' => 'x',
        'ed' => '',
        's' => '',
        // No "-er" (power, water, polymer, computer) and no "-ly" (anomaly,
        // assembly): both cost more matches than they make.
    ];

    public function stem(string $word): string
    {
        if (mb_strlen($word) <= 4 || ! preg_match('/^\p{L}+$/u', $word)) {
            return $word;
        }

        // "ss" endings (process, class) are not plurals.
        if (str_ends_with($word, 'ss')) {
            return $word;
        }

        foreach (self::SUFFIXES as $suffix => $replacement) {
            if (str_ends_with($word, $suffix)) {
                $stem = mb_substr($word, 0, -mb_strlen($suffix)).$replacement;

                if (mb_strlen($stem) >= 3) {
                    return $stem;
                }
            }
        }

        return $word;
    }
}
