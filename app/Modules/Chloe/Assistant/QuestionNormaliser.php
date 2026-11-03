<?php

namespace App\Modules\Chloe\Assistant;

/**
 * The deterministic layer's own reading aid for messy, informal text --
 * used ONLY by Pipeline's Part 5 fallback (the router itself always sees
 * the masked question exactly as typed; it understands "u", "pls" and
 * "lah" far better than any rewrite list could). Two jobs:
 *
 *  1. normalise(): lowercases, strips chat particles ("lah", "leh",
 *     "ah"...), expands the common short forms ("u" -> "you", "pls" ->
 *     "please", "wat" -> "what"...), and drops a polite request lead-in
 *     ("can u help me to ..." -> "..."), so the keyword rules and
 *     ParameterExtractor see the plain sentence underneath.
 *  2. isInterrogative(): the interrogative guard -- a question that asks
 *     how many / which / when / what / is there is a DATA question,
 *     never an action, even when it contains an action verb ("how many
 *     seat will be release in november" contains "release").
 *
 * Placeholders (<ID1>, <EMAIL1>) pass through untouched.
 */
final class QuestionNormaliser
{
    /** Whole-word short forms -> their plain spelling. */
    protected const SHORT_FORMS = [
        'u' => 'you', 'ur' => 'your', 'r' => 'are', 'pls' => 'please', 'plz' => 'please', 'pl' => 'please',
        'wat' => 'what', 'wht' => 'what', 'whr' => 'where', 'hw' => 'how', 'wen' => 'when', 'y' => 'why',
        'abt' => 'about', 'nd' => 'and', 'n' => 'and', 'thx' => 'thanks', 'tq' => 'thanks', 'cn' => 'can',
        'dunno' => "don't know", 'gonna' => 'going to', 'wanna' => 'want to', 'smth' => 'something',
        'msia' => 'malaysia', 'nxt' => 'next', 'mth' => 'month', 'mths' => 'months', 'yr' => 'year',
        'tmr' => 'tomorrow', 'ystd' => 'yesterday', 'bcoz' => 'because', 'cuz' => 'because',
    ];

    /** Malaysian-English discourse particles -- no meaning of their own for routing. */
    protected const PARTICLES = ['lah', 'la', 'leh', 'lor', 'ah', 'meh', 'mah', 'hor', 'ya', 'yah', 'one', 'eh', 'bro', 'sis'];

    public static function normalise(string $text): string
    {
        // Lowercased, but placeholders keep their exact case -- Pipeline
        // maps "<ID1>" back to the real value by exact key.
        $text = mb_strtolower($text);
        $text = preg_replace_callback('/<(id|email)(\d+)>/', fn ($m) => '<'.strtoupper($m[1]).$m[2].'>', $text) ?? $text;
        $text = preg_replace('/[?!.,;:]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        $words = explode(' ', $text);
        $out = [];

        foreach ($words as $word) {
            if ($word === '' || in_array($word, self::PARTICLES, true)) {
                continue;
            }

            $out[] = self::SHORT_FORMS[$word] ?? $word;
        }

        $text = implode(' ', $out);

        // A polite request lead-in carries no meaning of its own:
        // "can you help me to mark <ID1> as key collected" is the
        // imperative "mark <ID1> as key collected".
        $text = preg_replace('/^(please )?((can|could|would|will) you (please )?)?(help me (to )?|pls |please )?/', '', $text) ?? $text;
        // "got seat or not" -> "seat available"
        $text = preg_replace('/\bgot (\w+) or not\b/', '$1 available', $text) ?? $text;

        return trim($text);
    }

    /**
     * The interrogative guard. True for a question that ASKS for data
     * (how many / how much / which / when / what / is there / are there /
     * any ...?) -- never for a "how do I ..." how-to question, which is
     * guidance (KeywordRules' own guidance lead-ins handle that case) and
     * never for an imperative.
     */
    public static function isInterrogative(string $text): bool
    {
        $needle = mb_strtolower(trim($text));

        return (bool) preg_match('/^(how many|how much|how long|which|when|what|whats|what\'s|is there|are there|any|do we have|does .* have|did|got any|who)\b/', $needle)
            || (bool) preg_match('/\b(how many|how much)\b/', $needle);
    }
}
