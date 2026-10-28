<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Nureen\Models\GaCertificationDetail;
use Illuminate\Support\Facades\DB;

/**
 * CGS/GACERT/<year>/<sequence> -- unique, sequential within the year.
 *
 * Locks the table for the duration of the read-then-write so two
 * certificates generated in the same request cannot race onto the same
 * sequence number; cheap at FYP scale (one row write), not something that
 * needs a dedicated counter table.
 */
class CertificateReferenceGenerator
{
    public function generate(): string
    {
        $prefix = config('nureen.ga_certification.reference_prefix');
        $year = now()->year;

        return DB::transaction(function () use ($prefix, $year) {
            $count = GaCertificationDetail::where('reference_number', 'like', "{$prefix}/{$year}/%")
                ->lockForUpdate()
                ->count();

            $sequence = str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

            return "{$prefix}/{$year}/{$sequence}";
        });
    }
}
