<?php

namespace App\Modules\Core\Services\Concerns;

use App\Modules\Core\Contracts\SuppliesPostgraduateProgress;

/**
 * How a Core dashboard reaches Stage Gate / Allowance / TA Hours figures
 * without naming the module that owns them. See ReadsAttendance, the same
 * pattern for a different contract.
 *
 * Returns null when no module has bound SuppliesPostgraduateProgress; the
 * caller holds that panel's skeleton, exactly as the attendance panel
 * already does when nothing supplies it.
 */
trait ReadsPostgraduateProgress
{
    protected function postgraduateProgressSource(): ?SuppliesPostgraduateProgress
    {
        return app()->bound(SuppliesPostgraduateProgress::class)
            ? app(SuppliesPostgraduateProgress::class)
            : null;
    }
}
