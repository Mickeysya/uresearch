<?php

namespace App\Modules\Hani\Support\Matching;

/**
 * Text to matching terms: lower case, stop words out, light stemming, and
 * two-word phrases.
 *
 * Phrases matter because expertise is written in them: "anomaly detection"
 * is a much stronger signal than "anomaly" and "detection" separately. A
 * keyword list's multi-word entries are kept whole as phrase terms; free text
 * (a thesis title) contributes its adjacent word pairs, and the matcher keeps
 * only the pairs some examiner actually has, so the vocabulary does not fill
 * with "study of" and "towards a".
 *
 * Every term remembers the words it was made from (surface()), so a match can
 * be explained in the user's words ("anomaly detection"), not the stem's
 * ("anomaly detect").
 */
class Tokeniser
{
    protected const STOP_WORDS = [
        'a', 'about', 'above', 'across', 'after', 'against', 'all', 'also', 'an', 'and', 'any', 'are', 'as', 'at',
        'based', 'be', 'been', 'being', 'between', 'both', 'but', 'by', 'can', 'case', 'could', 'did', 'do', 'does',
        'during', 'each', 'for', 'from', 'further', 'had', 'has', 'have', 'having', 'how', 'however', 'if', 'in',
        'into', 'is', 'it', 'its', 'itself', 'may', 'more', 'most', 'new', 'no', 'not', 'novel', 'of', 'on', 'one',
        'or', 'other', 'our', 'over', 'proposed', 'such', 'than', 'that', 'the', 'their', 'them', 'then', 'there',
        'these', 'they', 'this', 'those', 'through', 'thus', 'to', 'towards', 'toward', 'under', 'up', 'upon',
        'use', 'used', 'using', 'via', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who',
        'will', 'with', 'within', 'without', 'would',
        // Words every thesis title has, which identify nobody's expertise.
        'study', 'studies', 'approach', 'approaches', 'analysis', 'investigation', 'development', 'developing',
        'design', 'evaluation', 'framework', 'method', 'methods', 'methodology', 'model', 'models', 'modelling',
        'modeling', 'system', 'systems', 'technique', 'techniques', 'application', 'applications', 'performance',
        'effect', 'effects', 'improved', 'improving', 'enhanced', 'enhancing', 'malaysia', 'malaysian',
    ];

    /** @var array<string, string> term => the words first seen for it */
    protected array $surfaces = [];

    public function __construct(protected Stemmer $stemmer) {}

    /**
     * Single-word terms from free text, plus its adjacent pairs as candidate
     * phrases.
     *
     * @return array{words: array<int, string>, pairs: array<int, string>}
     */
    public function text(?string $text): array
    {
        $raw = $this->words($text);
        $terms = [];
        $pairs = [];
        $previous = null;

        foreach ($raw as $word) {
            if ($this->isStopWord($word)) {
                $previous = null;

                continue;
            }

            $term = $this->term($word);
            $terms[] = $term;

            if ($previous !== null) {
                $pairs[] = $this->phrase([$previous[0], $word]);
            }

            $previous = [$word];
        }

        return ['words' => $terms, 'pairs' => $pairs];
    }

    /**
     * A keyword list ("machine learning; anomaly detection"): every entry's
     * words as single terms, and every multi-word entry as one phrase term.
     *
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    public function keywords(array $keywords): array
    {
        $terms = [];

        foreach ($keywords as $keyword) {
            $words = array_values(array_filter($this->words($keyword), fn ($w) => ! $this->isStopWord($w)));

            foreach ($words as $word) {
                $terms[] = $this->term($word);
            }

            if (count($words) >= 2) {
                $terms[] = $this->phrase($words);
            }

            // A longer entry ("mixed matrix membranes") also gives its word
            // pairs, so a title saying "mixed matrix" still meets it.
            for ($i = 0; count($words) > 2 && $i < count($words) - 1; $i++) {
                $terms[] = $this->phrase([$words[$i], $words[$i + 1]]);
            }
        }

        return $terms;
    }

    public function isPhrase(string $term): bool
    {
        return str_contains($term, ' ');
    }

    /** The words a term was made from, for showing it to a person. */
    public function surface(string $term): string
    {
        return $this->surfaces[$term] ?? $term;
    }

    /** @return array<string, string> */
    public function surfaces(): array
    {
        return $this->surfaces;
    }

    /** @param array<string, string> $surfaces */
    public function remember(array $surfaces): void
    {
        $this->surfaces += $surfaces;
    }

    /** @return array<int, string> */
    protected function words(?string $text): array
    {
        $text = mb_strtolower((string) $text);
        // Hyphens split ("Learning-Based" -> learning, based), on both the
        // thesis and the expertise side alike, so "pls-sem" still meets
        // "pls-sem". "co2" and "iot" survive; "1." numbering does not.
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return array_values(array_filter(
            preg_split('/\s+/u', $text),
            fn ($w) => $w !== '' && ! ctype_digit($w) && mb_strlen($w) > 1
        ));
    }

    protected function isStopWord(string $word): bool
    {
        return in_array($word, self::STOP_WORDS, true);
    }

    protected function term(string $word): string
    {
        $term = $this->stemmer->stem($word);
        $this->surfaces[$term] ??= $word;

        return $term;
    }

    /** @param array<int, string> $words */
    protected function phrase(array $words): string
    {
        $term = implode(' ', array_map(fn ($w) => $this->stemmer->stem($w), $words));
        $this->surfaces[$term] ??= implode(' ', $words);

        return $term;
    }
}
