<?php

namespace App\Modules\Hani\Exports;

use App\Modules\Hani\Support\Import\TemplateLayoutReader;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The blank UResearch pool template: Examiners, Publications and a README.
 *
 * Headings come from TemplateLayoutReader's own constants, so what CGS
 * downloads is exactly what the importer reads. No demo rows -- those live in
 * Database/demo/ and never leave it.
 */
class ExaminerTemplateExport implements Export, WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            self::sheet('Examiners', array_keys(TemplateLayoutReader::EXAMINER_HEADERS)),
            self::sheet('Publications', array_keys(TemplateLayoutReader::PUBLICATION_HEADERS)),
            self::sheet('README', ['UResearch examiner pool template'], [
                ['Examiners: one row per examiner. Type is internal or external.'],
                ['Email is optional. Without one the examiner is added, but shown as "Email needed" and skipped by invitations and letters.'],
                ['Department: a UResearch department name (aliases such as "Computer & Information Sciences" are accepted for internal examiners).'],
                ['Internal rows: the external-only columns are ignored.'],
                ['External rows: Institution is required.'],
                ['Keywords: semicolon-separated, e.g. "machine learning; anomaly detection". Used by the examiner suggestions.'],
                ['Status: Active or Unavailable. Unavailable requires a reason.'],
                ['Dates: YYYY-MM-DD or DD/MM/YYYY. Last Examination Date drives the 90-day gap.'],
                ['Current UTP Appointment / Former UTP Staff/Student Until: used by the conflict-of-interest pre-check (UTP/CGS/026).'],
                ['Publications: matched to the examiner by email. Co-authors are semicolon-separated.'],
                ['Re-importing the same file changes nothing; examiners are never deleted by an import.'],
            ]),
        ];
    }

    protected static function sheet(string $title, array $headings, array $rows = []): object
    {
        return new class($title, $headings, $rows) implements FromArray, WithHeadings, WithTitle
        {
            public function __construct(private string $title, private array $headings, private array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function title(): string
            {
                return $this->title;
            }
        };
    }
}
