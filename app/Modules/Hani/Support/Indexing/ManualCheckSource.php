<?php

namespace App\Modules\Hani\Support\Indexing;

/** No journal list: always "Needs manual check", with what was detected so the AE can look it up. */
class ManualCheckSource implements IndexingSource
{
    public function lookup(?string $journal, ?string $issn): array
    {
        $found = array_filter([$journal ? 'journal "'.$journal.'"' : null, $issn ? 'ISSN '.$issn : null]);

        return [
            'status' => 'not_checked',
            'detail' => 'Needs manual check — verify Scopus/WoS indexing'.($found ? ' ('.implode(', ', $found).')' : ' (no journal or ISSN detected)').'.',
        ];
    }
}
