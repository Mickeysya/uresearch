<?php

namespace App\Modules\Chloe\Support;

use Carbon\CarbonInterface;

/** What is known about one student's standing, and where each answer came from. Built only by StudentStatusReader. */
final class StudentStatus
{
    public const SHARED = 'shared';

    public const OVERLAY = 'overlay';

    public const RECORD = 'candidacy record';

    public const NONE = 'none';

    /** @param array{softbound:string,completed:string,inactive:string,dismissed:string} $sources */
    public function __construct(
        public readonly bool $softboundSubmitted,
        public readonly ?CarbonInterface $softboundDate,
        public readonly bool $completed,
        public readonly bool $inactive,
        public readonly bool $dismissed,
        public readonly array $sources,
    ) {}

    /** True when any value shown came from the demo overlay: the screen must then say "Demo data". */
    public function isDemoData(): bool
    {
        return in_array(self::OVERLAY, $this->sources, true);
    }

    /** Anything that ends the candidacy period or the student's standing: no reminder, no dismissal candidate. */
    public function stopsReminders(): bool
    {
        return $this->softboundSubmitted || $this->completed || $this->inactive || $this->dismissed;
    }
}
