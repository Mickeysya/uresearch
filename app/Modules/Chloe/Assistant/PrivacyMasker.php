<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Support\StudentDirectory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;

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
     * What is replaced before any text can reach a model, in this order (an earlier rule takes the digits first, so a later one never sees them):
     *
     *   1. e-mail addresses           -> <EMAIL1>  (kept in the map: the pipeline asks for a Student ID instead)
     *   2. Malaysian IC numbers       -> <IC1>     6 + 2 + 4 digits, with or without dashes
     *   3. phone numbers              -> <PHONE1>  any unbroken run of 9 or more digits (with or without a leading +), and a separated number that
     *                                              looks like a Malaysian phone number (starts 0, 60 or +60: "012-345 6789", "+60 12-345 6789", "03-1234 5678")
     *   4. an 8-digit student ID      -> <ID1>     exactly as before
     *
     * Phone and IC placeholders are NOT put in the map: nothing can be unmasked back into a real number, and a model that echoes one as a parameter
     * gets nothing. A separated run of ordinary numbers such as "seat 252 253 254", a date such as "2026-11-09 10:30" or "09-11-2026", and two
     * 8-digit IDs written next to each other are not phone numbers and are left to the ID rule.
     *
     * @return array{masked: string, idMap: array<string, string>}
     */
    public static function mask(string $text): array
    {
        $idMap = [];
        $emailCounter = 0;
        $idCounter = 0;
        $icCounter = 0;
        $phoneCounter = 0;

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
            '/(?<!\d)\d{6}-?\d{2}-?\d{4}(?!\d)/',
            function () use (&$icCounter) {
                $icCounter++;

                return "<IC{$icCounter}>";
            },
            $masked
        );

        $phone = function (array $m) use (&$phoneCounter) {
            $raw = $m[0];
            $parts = array_values(array_filter(preg_split('/[\s+-]+/', $raw)));

            // two or more 8-digit student IDs side by side are IDs, not one phone number
            if (count($parts) > 1 && collect($parts)->every(fn ($part) => preg_match('/^\d{8}$/', $part) === 1)) {
                return $raw;
            }

            // a date (and a time after it) is not a phone number
            if (preg_match('/^\d{4}-\d{2}-\d{2}|^\d{2}-\d{2}-\d{4}/', $raw) === 1) {
                return $raw;
            }

            $phoneCounter++;

            return "<PHONE{$phoneCounter}>";
        };

        // an unbroken run of 9 or more digits, then a separated Malaysian-looking number
        $masked = preg_replace_callback('/(?<!\d)\+?\d{9,}(?!\d)/', $phone, $masked);
        $masked = preg_replace_callback('/(?<![\d+])\+?(?:60|0)[ -]?\d{1,2}(?:[ -]?\d){7,9}(?!\d)/', $phone, $masked);

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

    /** The text with the same masking applied, for the last gate in front of the HTTP call (idempotent: a masked text is unchanged). */
    public static function scrub(string $text): string
    {
        return self::mask($text)['masked'];
    }

    /**
     * The earlier questions sent as history. Each is masked the same way; one that names a known student or staff member is dropped altogether
     * (it would have been refused when it was asked, but the browser still remembers it, and it must not travel with the next question).
     *
     * @param  array<int, mixed>  $questions
     * @return list<string>
     */
    public static function maskHistory(array $questions): array
    {
        $out = [];

        foreach ($questions as $question) {
            $question = (string) $question;

            if (self::containsKnownStudentName($question) || self::containsKnownStaffName($question)) {
                continue;
            }

            $out[] = self::mask($question)['masked'];
        }

        return $out;
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

    /**
     * True if $text contains the full name of a known supervisor, chair, Dean of PGR or CGS staff member (a login or a Study Candidacy staff
     * profile). Titles (Dr., Prof., Ir., Puan, En, Mr ...) and punctuation are ignored on both sides, so "dr aisyah rahman" matches "Dr. Aisyah
     * Rahman". Only a FULL name (two words or more after the titles) counts: a role word ("my supervisor", "the Dean") or a single first name is
     * never refused here.
     */
    public static function containsKnownStaffName(string $text): bool
    {
        $normalized = ' '.self::normaliseName($text).' ';

        if (trim($normalized) === '') {
            return false;
        }

        foreach (self::staffNames() as $name) {
            if (str_contains($normalized, ' '.$name.' ')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every staff name, lower case, titles removed, two words or more. */
    protected static function staffNames(): array
    {
        $roles = [Role::SUPERVISOR, Role::CHAIR, Role::DEAN_PGR, Role::NON_EXEC_CGS, Role::MANAGER_CGS, Role::SENIOR_DIRECTOR_CGS, Role::SENIOR_EXEC_CGS];
        $names = User::whereIn('role', $roles)->pluck('name')->merge(CandidacyStaffProfile::pluck('full_name'));

        return $names->map(fn ($n) => self::normaliseName((string) $n))
            ->filter(fn ($n) => count(explode(' ', $n)) >= 2)
            ->unique()->values()->all();
    }

    protected static function normaliseName(string $text): string
    {
        $t = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $t = ' '.trim(preg_replace('/\s+/', ' ', $t)).' ';
        // titles, wherever they stand
        $t = preg_replace('/\s(dr|prof|professor|assoc|ir|ts|puan|en|encik|mr|mrs|ms|miss|datuk|dato|datin|tan sri|haji|hajah)(?=\s)/', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }
}
