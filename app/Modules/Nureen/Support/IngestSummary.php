<?php

namespace App\Modules\Nureen\Support;

/**
 * What one AttendanceIngestor::ingest() call did, for both callers: the
 * manual upload page builds its own existing "Processed N records..."
 * message from these numbers, and `attendance:sync` stores them on
 * AttendanceSyncRun for the status panel and reports them with text().
 */
final readonly class IngestSummary
{
    public function __construct(
        public int $read,
        public int $added,
        public int $updated,
        public int $rejected,
        public int $newlyAtRisk,
        /** @var array<int, string> */
        public array $reasons,
    ) {}

    public function processed(): int
    {
        return $this->added + $this->updated;
    }

    public function text(): string
    {
        return "Read {$this->read}. Added {$this->added}, updated {$this->updated}, "
            ."rejected {$this->rejected}. {$this->newlyAtRisk} newly flagged at-risk.";
    }
}
