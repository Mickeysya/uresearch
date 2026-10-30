<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Nureen\Models\SupervisionDetail;
use Illuminate\Support\Facades\DB;

/**
 * CGS/SVAPPT/<year>/<sequence> -- unique, sequential within the year.
 *
 * The same race-safe approach as CertificateReferenceGenerator: lock the
 * table for the duration of the read-then-write so two letters generated in
 * the same request cannot land on the same sequence number.
 */
class AppointmentReferenceGenerator
{
    public function generate(): string
    {
        $prefix = config('nureen.supervision.reference_prefix');
        $year = now()->year;

        return DB::transaction(function () use ($prefix, $year) {
            $count = SupervisionDetail::where('letter_reference', 'like', "{$prefix}/{$year}/%")
                ->lockForUpdate()
                ->count();

            $sequence = str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

            return "{$prefix}/{$year}/{$sequence}";
        });
    }
}
