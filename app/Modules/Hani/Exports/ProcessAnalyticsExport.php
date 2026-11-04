<?php

namespace App\Modules\Hani\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The Process Analytics tables as a file, from Support\ProcessAnalytics::tables(),
 * so the file holds exactly what the page showed for the same period and scope.
 *
 * xlsx: one sheet per table. CSV has no sheets, so ProcessAnalyticsCsvExport
 * writes the same tables one under another, each row led by its table name.
 */
class ProcessAnalyticsExport implements Export, WithMultipleSheets
{
    /** @param  array<string, array{headings: array<int, string>, rows: array<int, array<int, mixed>>}>  $tables */
    public function __construct(protected array $tables) {}

    public function sheets(): array
    {
        $sheets = [];
        foreach ($this->tables as $title => $table) {
            $sheets[] = new class($title, $table) implements FromArray, WithHeadings, WithTitle
            {
                public function __construct(protected string $title, protected array $table) {}

                public function title(): string
                {
                    return substr($this->title, 0, 31);
                }

                public function headings(): array
                {
                    return $this->table['headings'];
                }

                public function array(): array
                {
                    return $this->table['rows'];
                }
            };
        }

        return $sheets;
    }
}
