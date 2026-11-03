<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Department;

/**
 * Turns whatever a spreadsheet calls a department into the name Core's
 * departments list uses.
 *
 * The department rule (an internal examiner comes from the candidate's own
 * department) compares `examiners.department` with `users.department` as
 * plain strings, and accounts carry the canonical names ("Computing"). CGS's
 * sheets do not: they say "Computer & Information Sciences" in one place and
 * "Computer and Information Sciences" in another. An examiner imported under
 * either would never satisfy the rule, so every name is resolved here first,
 * and one that cannot be resolved is refused rather than stored as-is.
 */
class Departments
{
    /** @var array<string, array{name: string, faculty: ?string}>|null normalised name => canonical */
    protected ?array $canonical = null;

    /**
     * Lower case, "&" read as "and", punctuation dropped, spaces collapsed.
     * The comparison key, never something to display.
     */
    public static function normalise(?string $name): string
    {
        $name = mb_strtolower((string) $name);
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * The canonical department for $name, with its faculty, or null when
     * neither Core's list nor the alias list knows it.
     *
     * @return array{name: string, faculty: ?string}|null
     */
    public function resolve(?string $name): ?array
    {
        $key = self::normalise($name);

        if ($key === '') {
            return null;
        }

        $canonical = $this->canonical();

        if (isset($canonical[$key])) {
            return $canonical[$key];
        }

        foreach (config('hani.departments.aliases', []) as $alias => $target) {
            if (self::normalise($alias) === $key) {
                return $canonical[self::normalise($target)] ?? null;
            }
        }

        return null;
    }

    /** True when two names resolve to, or already are, the same department. */
    public function same(?string $a, ?string $b): bool
    {
        $resolvedA = $this->resolve($a)['name'] ?? self::normalise($a);
        $resolvedB = $this->resolve($b)['name'] ?? self::normalise($b);

        return $resolvedA !== '' && $resolvedA === $resolvedB;
    }

    /** @return array<string, array{name: string, faculty: ?string}> */
    protected function canonical(): array
    {
        return $this->canonical ??= Department::query()
            ->get(['name', 'faculty'])
            ->mapWithKeys(fn (Department $d) => [
                self::normalise($d->name) => ['name' => $d->name, 'faculty' => $d->faculty],
            ])
            ->all();
    }
}
