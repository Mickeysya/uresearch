<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Support\CandidacyDismissalList as L;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * DEMO ONLY: writes storage/app/demo/registry-confirmed-sample.xlsx, the file a Registry officer would send back: about half of today's dismissal
 * candidates plus a few rows that must NOT be applied (an unknown ID, a student who is not a candidate, a repeated row), so the upload page can show
 * every status. Run candidacy:demo-seed first.
 */
class CandidacyDemoRegistryFile extends Command
{
    protected $signature = 'candidacy:demo-registry-file';

    protected $description = 'DEMO ONLY: write storage/app/demo/registry-confirmed-sample.xlsx for the Dismissal Upload demo';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('candidacy:demo-registry-file refuses to run in production.');

            return self::FAILURE;
        }

        $candidates = L::candidates()->values();

        if ($candidates->isEmpty()) {
            $this->error('There are no dismissal candidates. Run candidacy:demo-seed first.');

            return self::FAILURE;
        }

        $confirmed = $candidates->filter(fn ($r, $i) => $i % 2 === 0)->values();
        $notCandidate = L::all()->first(fn ($r) => ! $r['candidate'] && $r['code'] === 'softbound');
        $rows = $confirmed->map(fn ($r) => [$r['studentId'], $r['name'], $r['programme'], 'Confirmed by Registry'])->all();

        $rows[] = ['99999999', 'Not in the system', '—', 'Confirmed by Registry'];
        $rows[] = [$notCandidate['studentId'] ?? '21020015', $notCandidate['name'] ?? 'Softbound student', $notCandidate['programme'] ?? '—', 'Confirmed by Registry'];
        $rows[] = [$confirmed->first()['studentId'], $confirmed->first()['name'], $confirmed->first()['programme'], 'Confirmed by Registry (repeated row)'];

        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray(array_merge([['Student ID', 'Name', 'Programme', 'Registry remark']], $rows), null, 'A1', true);
        $sheet->getActiveSheet()->getStyle('A1:D1')->getFont()->setBold(true);

        $path = storage_path('app/demo/registry-confirmed-sample.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new Xlsx($sheet))->save($path);

        $this->info('Wrote storage/app/demo/registry-confirmed-sample.xlsx: '.$confirmed->count().' of '.$candidates->count().' candidates plus 3 rows that will not be applied.');

        return self::SUCCESS;
    }
}
