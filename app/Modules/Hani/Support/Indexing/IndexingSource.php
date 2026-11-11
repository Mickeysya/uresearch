<?php

namespace App\Modules\Hani\Support\Indexing;

/**
 * Whether a journal is indexed (Scopus / WoS / ERA, quartile). There is no
 * CGS journal list yet (UAT 11 Nov 2026), so the only source is
 * ManualCheckSource. A list CGS supplies later implements this and is named
 * in hani.notice_checks.indexing_source; nothing else changes.
 */
interface IndexingSource
{
    /**
     * @return array{status: string, detail: string} status: pass | warning | not_checked
     */
    public function lookup(?string $journal, ?string $issn): array;
}
