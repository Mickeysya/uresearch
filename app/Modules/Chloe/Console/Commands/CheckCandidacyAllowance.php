<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\StudyCandidacy;
use Illuminate\Console\Command;

/**
 * Read-only consistency check: for every candidacy, extension_months_used must equal the sum of approved_months of
 * its approved appeals, and the current expiry must equal original_expiry_date plus that sum (calendar months, no
 * month-end overflow). Exit code 1 when anything disagrees.
 */
class CheckCandidacyAllowance extends Command
{
    protected $signature = 'candidacy:check-allowance';

    protected $description = 'Verify extension_months_used and the expiry date against the approved appeals';

    public function handle(): int
    {
        $rows = [];
        $bad = 0;

        foreach (StudyCandidacy::orderBy('id')->get() as $candidacy) {
            $sum = (int) CandidacyAppeal::where('study_candidacy_id', $candidacy->id)->where('status', CandidacyAppeal::STATUS_APPROVED)->sum('approved_months');

            if ($candidacy->original_expiry_date === null) {
                $rows[] = [$candidacy->id, $candidacy->extension_months_used, $sum, '-', $candidacy->candidacy_expiry_date->toDateString(), 'SKIPPED (no original expiry date yet -- run candidacy:backfill-appeals)'];

                continue;
            }

            $expected = $candidacy->original_expiry_date->copy()->addMonthsNoOverflow($sum);
            $problems = [];

            if ((int) $candidacy->extension_months_used !== $sum) {
                $problems[] = "months used {$candidacy->extension_months_used} != approved sum {$sum}";
            }
            if (! $expected->isSameDay($candidacy->candidacy_expiry_date)) {
                $problems[] = "expiry {$candidacy->candidacy_expiry_date->toDateString()} != original + {$sum} = {$expected->toDateString()}";
            }

            $bad += $problems ? 1 : 0;
            $rows[] = [$candidacy->id, $candidacy->extension_months_used, $sum, $expected->toDateString(), $candidacy->candidacy_expiry_date->toDateString(), $problems ? 'MISMATCH: '.implode('; ', $problems) : 'OK'];
        }

        $this->table(['Candidacy', 'Months used', 'Approved sum', 'Expected expiry', 'Current expiry', 'Result'], $rows);

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
