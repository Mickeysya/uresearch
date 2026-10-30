<?php

namespace App\Modules\Chloe\Assistant;

/**
 * Pure, deterministic pattern matching over an already-masked question --
 * never a lookup/dispatch decision of its own (see KeywordRules::
 * extractDeterministic() for that). Every method here either finds its
 * one thing or returns null/false; nothing here guesses.
 */
final class ParameterExtractor
{
    /** "block j2", "block N2", or a bare "n2"/"j2" word -- case-insensitive, word-boundary matched so "n2" never matches inside a longer token. */
    public static function block(string $text): ?string
    {
        if (preg_match('/\bblock\s+(n2|j2)\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\b(n2|j2)\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /** A room code shaped like "N2-02-12" -- the actual block/sequence digits are not validated here, only the shape; the lookup itself checks the room really exists. */
    public static function roomCode(string $text): ?string
    {
        if (preg_match('/\b([nj]2-\d{2}-\d{2})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /** "seat 252", "seat #252", or a bare "#252" -- 1 to 315 only, matching the real Workstation id range. */
    public static function seatNumber(string $text): ?int
    {
        if (preg_match('/\bseat\s*#?\s*(\d{1,3})\b/i', $text, $m) || preg_match('/#(\d{1,3})\b/', $text, $m)) {
            $n = (int) $m[1];

            return ($n >= 1 && $n <= 315) ? $n : null;
        }

        return null;
    }

    /** PrivacyMasker has already replaced every 8-digit ID with a placeholder by the time this runs -- so this looks for the placeholder, never a raw digit run. Pipeline maps it back to the real ID afterwards. */
    public static function studentIdPlaceholder(string $text): ?string
    {
        return preg_match('/<ID\d+>/', $text, $m) ? $m[0] : null;
    }

    /** "6 months", "next 3 months" -- 1 to 12 only, matching candidacy_expiring's own validation range. */
    public static function months(string $text): ?int
    {
        if (preg_match('/\b(\d{1,2})\s+months?\b/i', $text, $m)) {
            $n = (int) $m[1];

            return ($n >= 1 && $n <= 12) ? $n : null;
        }

        return null;
    }

    /**
     * True only for an imperative ("Release seat 252"), never a how-to
     * question about the same verb ("How do I release a seat?") -- UNLESS
     * the question also clearly names a seat number to act on, in which
     * case it stays an action request even when phrased as a question
     * (PART 1 #1's own example boundary: "How do I release a seat?" is
     * guidance; a hypothetical "How do I release seat 252?" would not
     * be, since a specific target is named).
     */
    public static function hasActionVerb(string $text): bool
    {
        $needle = mb_strtolower($text);

        // The interrogative guard: "how many seat will be release in
        // november" asks for data, it does not ask us to release anything.
        if (QuestionNormaliser::isInterrogative($needle)) {
            return false;
        }

        if (self::hasGuidanceLeadIn($needle) && self::seatNumber($text) === null) {
            return false;
        }

        // Word-boundary, not a bare substring -- found live, against the
        // real provider: "How many seats were released this week?"
        // contains "release" as a mid-word substring of "released", which
        // a plain str_contains() wrongly treated as the command "release".
        foreach (config('assistant.action_phrases', []) as $phrase) {
            $pattern = '/\b'.preg_quote(mb_strtolower($phrase), '/').'\b/';

            if (preg_match($pattern, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function hasManipulationPhrase(string $text): bool
    {
        $needle = mb_strtolower($text);

        foreach (config('assistant.manipulation_phrases', []) as $phrase) {
            if (str_contains($needle, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    /** PART 1 #2: "lost my key", "key got lost", "missing key", "damaged key"... -- role-routed by KeywordRules::extractDeterministic(). */
    public static function hasLostOrDamagedKeyPhrase(string $text): bool
    {
        $needle = mb_strtolower($text);

        foreach (config('assistant.lost_or_damaged_key_phrases', []) as $phrase) {
            if (str_contains($needle, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    /** PART 1 #3: "mark [a/the] key as collected/returned", however the question is phrased around it. */
    public static function hasMarkKeyStatusPhrase(string $text): bool
    {
        $pattern = config('assistant.mark_key_status_pattern');

        return $pattern && preg_match($pattern, mb_strtolower($text)) === 1;
    }

    /** PART 1 #4: the model said out_of_scope, but does the question still sound like it's about this system at all? */
    public static function hasDomainWord(string $text): bool
    {
        $needle = mb_strtolower($text);

        foreach (config('assistant.domain_words', []) as $word) {
            if (str_contains($needle, mb_strtolower($word))) {
                return true;
            }
        }

        return false;
    }

    public static function hasGuidanceLeadIn(string $text): bool
    {
        $needle = mb_strtolower($text);

        foreach (config('assistant.guidance_lead_ins', []) as $leadIn) {
            if (str_contains($needle, $leadIn)) {
                return true;
            }
        }

        return false;
    }
}
