<?php

namespace App\Modules\Chloe\Assistant;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;

/**
 * The two assistant domains, "workstation" and "candidacy": one engine, separate content. Everything that is CONTENT (catalogue, suggested questions, guidance topics, router prompt,
 * evaluation set, log value) belongs to exactly one domain, and a question is only ever answered inside the domain of the page it was asked on.
 *
 *  - Which page is in which domain: config('assistant.domain_pages'), by route name (one line per new page).
 *  - The widget is rendered with its domain and a token that binds (domain, user, page); the server rejects a request whose token does not match what it claims.
 *  - Which roles use a domain: config('assistant.domains.<d>.roles'). An approver has no Workstation; a CGS user who also approves is just CGS on a Workstation page.
 *  - A question that clearly belongs to the other domain (strong terms) is redirected WITHOUT a model call.
 */
final class AssistantDomains
{
    public const WORKSTATION = 'workstation';

    public const CANDIDACY = 'candidacy';

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys((array) config('assistant.domains'));
    }

    public static function valid(?string $domain): bool
    {
        return $domain !== null && in_array($domain, self::all(), true);
    }

    public static function other(string $domain): string
    {
        return $domain === self::CANDIDACY ? self::WORKSTATION : self::CANDIDACY;
    }

    /** The domain of the page with this route name, or null (an unmapped page has no assistant domain; the assistant endpoints themselves are mapped to null on purpose). */
    public static function forPage(?string $routeName): ?string
    {
        foreach ((array) config('assistant.domain_pages') as $pattern => $domain) {
            if ($routeName !== null && \Illuminate\Support\Str::is($pattern, $routeName)) {
                return $domain;
            }
        }

        return null;
    }

    public static function title(string $domain): string
    {
        return (string) config("assistant.domains.{$domain}.title");
    }

    public static function placeholder(string $domain): string
    {
        return (string) config("assistant.domains.{$domain}.placeholder");
    }

    /** The role token the user has IN this domain, or null when the domain does not serve them (e.g. an approver on a Workstation page). */
    public static function effectiveRole(User $user, string $domain): ?string
    {
        $role = AssistantRoles::roleFor($user);

        if ($role === null) {
            return null;
        }

        // a CGS user who also approves is "cgs" wherever approver entries do not exist
        $plain = array_values(array_intersect(AssistantRoles::set($role), (array) config("assistant.domains.{$domain}.roles")));

        if ($plain === []) {
            return null;
        }

        return count($plain) === 2 ? AssistantRoles::CGS_APPROVER : $plain[0];
    }

    // ------------------------------------------------------------------ the widget's token

    public static function token(string $domain, int $userId, string $page): string
    {
        return hash_hmac('sha256', "{$domain}|{$userId}|{$page}", (string) config('app.key'));
    }

    /** True when the claimed domain is the one this page is mapped to and the token was issued for exactly (domain, user, page). */
    public static function accepts(User $user, ?string $domain, ?string $page, ?string $token): bool
    {
        return self::valid($domain) && $page !== null && self::forPage($page) === $domain && is_string($token)
            && hash_equals(self::token($domain, $user->id, $page), $token) && self::effectiveRole($user, $domain) !== null;
    }

    // ------------------------------------------------------------------ strong terms and the redirect

    public static function hasStrongTerm(string $normalisedQuestion, string $domain): bool
    {
        return self::strongTermsIn($normalisedQuestion, $domain) !== [];
    }

    /** @return list<string> */
    protected static function strongTermsIn(string $text, string $domain): array
    {
        $found = [];

        foreach ((array) config("assistant.domains.{$domain}.strong_terms") as $term) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?![a-z0-9])/i', $text) === 1) {
                $found[] = $term;
            }
        }

        return $found;
    }

    /**
     * The other domain's name when the question clearly belongs to it: it has strong terms of the other domain and none of the current one. One exception: "candidacy" next to an
     * expiry word ("when does my candidacy expire") is not enough on its own, because a workstation loan runs to the candidacy expiry date; the Workstation catalogue then decides
     * (and the redirect still follows if it has no entry for it).
     */
    public static function belongsToOther(string $normalisedQuestion, string $current): ?string
    {
        if (self::hasStrongTerm($normalisedQuestion, $current)) {
            return null;
        }

        $other = self::other($current);
        $terms = self::strongTermsIn($normalisedQuestion, $other);

        if ($terms === []) {
            return null;
        }

        if ($current === self::WORKSTATION && array_diff($terms, ['candidacy', 'candidature']) === [] && preg_match('/\b(expir\w*|end|ends|ending|finish\w*|deadline)\b/', $normalisedQuestion)) {
            return null;
        }

        return $other;
    }

    public static function redirectMessage(string $current): string
    {
        return (string) config("assistant.domains.{$current}.redirect");
    }

    /** @return array<int, array{label:string,url:string}> the user's page in the OTHER domain */
    public static function redirectLinks(User $user, string $current): array
    {
        $role = self::effectiveRole($user, $current);
        $plain = $role === null ? [] : AssistantRoles::set($role);

        if ($current === self::WORKSTATION) {
            return match (true) {
                in_array(AssistantRoles::CGS, $plain, true) => [['label' => 'Study Candidacy Operations', 'url' => route('candidacy.cgs.operations')]],
                default => [['label' => 'My Study Candidacy', 'url' => route('candidacy.status')]],
            };
        }

        return match (true) {
            in_array(AssistantRoles::CGS, $plain, true) => [['label' => 'Workstation Management', 'url' => route('workstation.cgs.operations')]],
            in_array(Role::STUDENT, $plain, true) => [['label' => 'Workstation page', 'url' => route('workstation.select')]],
            default => [['label' => 'Dashboard', 'url' => route('dashboard')]], // an approver has no Workstation page
        };
    }
}
