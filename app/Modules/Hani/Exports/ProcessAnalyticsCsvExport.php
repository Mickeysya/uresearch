<?php

namespace App\Modules\Hani\Exports;

use Maatwebsite\Excel\Concerns\FromArray;

/**
 * The Process Analytics tables as one CSV: each table's heading row, then its
 * rows, every line led by the table name, with a blank line between tables.
 * The xlsx version (ProcessAnalyticsExport) gives each table its own sheet.
 */
class ProcessAnalyticsCsvExport implements FromArray
{
    /** @param  array<string, array{headings: array<int, string>, rows: array<int, array<int, mixed>>}>  $tables */
    public function __construct(protected array $tables, protected string $caption) {}

    public function array(): array
    {
        $lines = [[$this->caption], []];

        foreach ($this->tables as $title => $table) {
            $lines[] = array_merge(['Table'], $table['headings']);
            foreach ($table['rows'] as $row) {
                $lines[] = array_merge([$title], $row);
            }
            $lines[] = [];
        }

        return $lines;
    }
}
