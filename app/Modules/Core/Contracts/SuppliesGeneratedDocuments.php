<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;

/**
 * OPTIONAL companion to WorkflowModule: files a module renders on demand
 * rather than storing, for the Documents page to list beside the stored
 * ones -- and display names for stored document types that would otherwise
 * be confused with them.
 *
 * Core never stores, renders or authorises these. The module returns an
 * entry only when $viewer may open it, under the same rule its own route
 * enforces, so the page can never show a link that 403s. Checked with
 * instanceof; a module without it lists only its stored files.
 */
interface SuppliesGeneratedDocuments
{
    /**
     * @return list<array{label: string, name: string, url: string, download: string}>
     *         url opens inline (the preview overlay), download is the attachment
     */
    public function generatedDocuments(Application $application, User $viewer): array;

    /** A display name for a stored doc_type, or null to show it as stored. */
    public function documentTypeLabel(string $docType): ?string;
}
