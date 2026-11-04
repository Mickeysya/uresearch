<?php

namespace App\Modules\Chloe\Support;

use Illuminate\Http\Request;

/**
 * Where "Back" on the appeal page goes: the page the user came from, with its tab, search, filters and page number.
 * The origin travels in a `return` query parameter and is accepted ONLY if it is an internal candidacy path
 * (never an absolute URL, a protocol-relative URL, a backslash trick or anything outside the candidacy pages).
 */
final class CandidacyReturnPath
{
    // '/signatures' is Core's "My Signatures" page, which carries this
    // module's upload and remove forms as one of its sections.
    private const ALLOWED_PREFIXES = ['/candidacy', '/cgs/candidacy', '/signatures'];

    /** @return string a safe relative path (with its query string), or null when the value is not acceptable */
    public static function validate(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) > 1500) {
            return null;
        }

        if ($value[0] !== '/' || str_starts_with($value, '//') || preg_match('/[\\\\\x00-\x1f]/', $value) || str_contains($value, '://')) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);

        if (! is_string($path) || str_contains($path, '..')) {
            return null;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $value;
            }
        }

        return null;
    }

    public static function resolve(Request $request, string $default): string
    {
        return self::validate($request->input('return')) ?? $default;
    }

    /** The relative URL of the page being shown now (used by lists to tell the detail page where to come back to). */
    public static function current(Request $request, string $routePath, array $query): string
    {
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');

        return $routePath.($query ? '?'.http_build_query($query) : '');
    }
}
