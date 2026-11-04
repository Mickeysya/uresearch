<?php

namespace App\Modules\Hani\Support\Similarity;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * What a SimilaritySource got out of a Turnitin report. ok = false means
 * nothing usable was read; $failure says why, in words a student can act on.
 */
final class SimilarityReading
{
    /**
     * @param  array<int, array{rank: int, source: string, type: string, percent: string, value: float}>  $sources  top sources
     * @param  array{quotes: ?string, bibliography: ?string, matches: ?string}  $exclusions  as printed
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $source,
        public readonly ?string $failure = null,
        public readonly ?string $title = null,
        public readonly ?float $index = null,
        public readonly ?float $internet = null,
        public readonly ?float $publications = null,
        public readonly ?float $studentPapers = null,
        public readonly array $sources = [],
        public readonly array $exclusions = ['quotes' => null, 'bibliography' => null, 'matches' => null],
        public readonly ?string $submittedAt = null,
        public readonly ?string $submissionId = null,
        public readonly ?string $engine = null,
    ) {}

    public static function failed(string $source, string $failure, ?string $engine = null): self
    {
        return new self(false, $source, $failure, engine: $engine);
    }

    /** The receipt's submission date, when there was a receipt page and it parsed. */
    public function submittedOn(): ?CarbonInterface
    {
        if ($this->submittedAt === null) {
            return null;
        }

        try {
            // "03-Nov-2026 10:15AM (UTC+0800)": the bracketed zone is
            // informational; the time is local to it.
            $clean = trim(preg_replace('/\(UTC([+-]\d{2})(\d{2})\)/', '$1$2', $this->submittedAt));

            return Carbon::parse($clean)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(?array $data): ?self
    {
        if (! $data || ! array_key_exists('ok', $data)) {
            return null;
        }

        return new self(...array_intersect_key($data, array_flip([
            'ok', 'source', 'failure', 'title', 'index', 'internet', 'publications', 'studentPapers',
            'sources', 'exclusions', 'submittedAt', 'submissionId', 'engine',
        ])));
    }
}
