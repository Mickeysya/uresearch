<?php

namespace App\Modules\Chloe\Assistant;

/**
 * PART 2: tried before the model, on the already-masked question. A
 * confident match skips the model entirely -- cheaper and more reliable
 * than letting a small free-tier model guess at a common phrasing. Phrase
 * lists live in config('assistant.keyword_rules'), never hard-coded here,
 * so adding a phrase is a config edit, not a code change.
 */
final class KeywordRules
{
    /** Deliberately not "N2" or "J2" -- Lookups::seatAvailability()'s own validation already asks back for any block string it doesn't recognise, so this reuses that path. */
    protected const UNSPECIFIED_BLOCK = '__unspecified__';

    /**
     * Every rule name whose phrase list matches, longest matching phrase
     * first -- a question can legitimately match more than one (e.g. "How
     * do I return my workstation?" contains both "my workstation" and the
     * more specific "return my workstation"), and the longer phrase is the
     * one that actually represents what was asked. Found live, via
     * assistant:evaluate: ranking by plain config order instead let a
     * short, generic lookup phrase ("my locker key") win over a far more
     * specific guidance phrase ("collect my locker key") every time one
     * happened to be defined earlier in the config array -- an ordering
     * accident, not an intentional priority. Ties (equal length) keep
     * config order, via PHP's stable sort.
     *
     * @return array<int, string>
     */
    public static function matchAll(string $maskedQuestion): array
    {
        $needle = mb_strtolower($maskedQuestion);

        /** @var array<string, int> rule name => length of its best (longest) matching phrase */
        $bestLengthByName = [];

        foreach (config('assistant.keyword_rules', []) as $name => $phrases) {
            foreach ($phrases as $phrase) {
                $phraseLower = mb_strtolower($phrase);

                if (! str_contains($needle, $phraseLower)) {
                    continue;
                }

                $length = mb_strlen($phraseLower);

                if (! isset($bestLengthByName[$name]) || $length > $bestLengthByName[$name]) {
                    $bestLengthByName[$name] = $length;
                }
            }
        }

        arsort($bestLengthByName);

        return array_keys($bestLengthByName);
    }

    /**
     * Tried BEFORE matchAll()'s phrase lists -- parameter-driven matches
     * that a plain phrase list can't express, since the lookup needs the
     * extracted value itself (e.g. "J2 free seats?" has no literal phrase
     * in config, but combines a seat-availability cue with an extractable
     * block). Checked in this order: manipulation phrases, then action
     * verbs (both refuse/redirect outright, no lookup needed), then each
     * parameter-carrying lookup, most unambiguous signal first -- an
     * explicit seat number or ID placeholder can only mean one thing, so
     * those are checked before the looser "cue + block" combination.
     *
     * Returns null, not an empty array, when nothing confidently matches
     * (PART 2's own ask-back/fall-through rule: "free seats" alone, with
     * no block, is incomplete, not wrong -- it falls through to whatever
     * already handles that case, unchanged).
     *
     * $role (student|cgs): needed for exactly two phrasings where the
     * SAME words mean a different topic depending on who asked --
     * "my key got lost" and "mark the key as collected" both route to
     * key_lost/locker_key_collect_return for a student, cgs_keys for
     * CGS -- a plain phrase list can only point at one topic name, so
     * this is handled here instead (PART 1 #2/#3).
     *
     * @return null|array{name: string, parameters: array<string, mixed>}
     */
    public static function extractDeterministic(string $maskedQuestion, string $role): ?array
    {
        $needle = mb_strtolower($maskedQuestion);

        if (ParameterExtractor::hasManipulationPhrase($maskedQuestion)) {
            return ['name' => 'out_of_scope', 'parameters' => []];
        }

        if (ParameterExtractor::hasActionVerb($maskedQuestion)) {
            $seat = ParameterExtractor::seatNumber($maskedQuestion);

            return ['name' => 'action_request', 'parameters' => array_filter(['seat_number' => $seat], fn ($v) => $v !== null)];
        }

        if (ParameterExtractor::hasLostOrDamagedKeyPhrase($maskedQuestion) || ParameterExtractor::hasMarkKeyStatusPhrase($maskedQuestion)) {
            $topic = AssistantRoles::hasCgs($role) ? 'cgs_keys' : 'key_lost';

            // "How do I mark a key as collected?" from a student is not
            // key_lost at all -- it's asking how the already-known
            // collect/return flow works, so it gets that topic instead;
            // a genuine lost/damaged key always goes to key_lost.
            if (! AssistantRoles::hasCgs($role) && ParameterExtractor::hasMarkKeyStatusPhrase($maskedQuestion) && ! ParameterExtractor::hasLostOrDamagedKeyPhrase($maskedQuestion)) {
                $topic = 'locker_key_collect_return';
            }

            return ['name' => "guidance:{$topic}", 'parameters' => []];
        }

        $seat = ParameterExtractor::seatNumber($maskedQuestion);

        if ($seat !== null) {
            return ['name' => 'seat_lookup', 'parameters' => ['seat_number' => $seat]];
        }

        $idPlaceholder = ParameterExtractor::studentIdPlaceholder($maskedQuestion);

        if ($idPlaceholder !== null) {
            return ['name' => 'student_lookup', 'parameters' => ['student_id' => $idPlaceholder]];
        }

        $months = ParameterExtractor::months($maskedQuestion);

        if ($months !== null && str_contains($needle, 'expir')) {
            return ['name' => 'candidacy_expiring', 'parameters' => ['months' => $months]];
        }

        if (self::hasSeatAvailabilityCue($needle)) {
            $roomCode = ParameterExtractor::roomCode($maskedQuestion);
            $block = ParameterExtractor::block($maskedQuestion);

            if ($roomCode !== null || $block !== null) {
                return ['name' => 'seat_availability', 'parameters' => array_filter([
                    'room_code' => $roomCode,
                    'block' => $roomCode === null ? $block : null,
                ], fn ($v) => $v !== null)];
            }

            // Explicitly asked for a block/room-scoped answer ("...in
            // block?") but never named one -- found live, against the
            // real provider: this used to silently fall through to the
            // broader overall_stats phrase match instead of asking back,
            // the exact "ambiguous/incomplete" case PART 2 says must keep
            // the ask-back behaviour. Lookups::seatAvailability() already
            // asks back for any block string it doesn't recognise, so an
            // obviously-invalid sentinel reuses that path with no change
            // to the lookup itself.
            if (preg_match('/\b(block|room)\b/', $needle)) {
                return ['name' => 'seat_availability', 'parameters' => ['block' => self::UNSPECIFIED_BLOCK]];
            }
        }

        return null;
    }

    /** "seats" plus an availability-ish word -- e.g. "J2 free seats?", "how many seats are available". Neither word alone is specific enough (PART 2's own false-positive risk otherwise). */
    protected static function hasSeatAvailabilityCue(string $needle): bool
    {
        $hasSeatsWord = (bool) preg_match('/\bseats?\b/', $needle);
        $hasAvailabilityWord = str_contains($needle, 'available') || str_contains($needle, 'free')
            || str_contains($needle, 'occupancy') || str_contains($needle, 'vacant');

        return $hasSeatsWord && $hasAvailabilityWord;
    }
}
