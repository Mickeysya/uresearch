<?php

namespace App\Modules\Core\Support;

/**
 * A "go back to where you came from" value carried in a request, accepted
 * only when it is a relative path on this site: never an absolute URL, a
 * protocol-relative `//host`, a backslash trick or a control character. So it
 * can be put in a redirect or an href without becoming an open redirect.
 *
 * Same rules as Chloe's CandidacyReturnPath, without its candidacy-only
 * prefix list -- My Signatures is reached from several modules' pages.
 */
final class SafeReturnPath
{
    public static function validate(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) > 1500) {
            return null;
        }

        if ($value[0] !== '/' || str_starts_with($value, '//') || str_contains($value, '://')
            || preg_match('/[\\\\\x00-\x1f]/', $value)) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && ! str_contains($path, '..') ? $value : null;
    }
}
