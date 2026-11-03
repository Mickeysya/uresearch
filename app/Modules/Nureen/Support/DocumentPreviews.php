<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApplicationDocument;
use App\Modules\Core\Models\User;

/**
 * The rows of an approver's Documents section: every file attached to the
 * application, plus the documents the module generates, each with a View
 * URL (opened in the preview overlay, components/file-preview) and a
 * Download URL.
 *
 * Viewing uses the same authorisation as downloading: attached files go
 * through ApplicationDocument::visibleTo() (Core's documents.show rule), and
 * each generated document's View route calls the same check as its
 * Download route. Nobody can view a file they couldn't already download.
 */
final class DocumentPreviews
{
    /** What the overlay can show inline. */
    public const PDF = 'pdf';

    public const IMAGE = 'image';

    /** Word files: "Preview not available"; no conversion. */
    public const WORD = 'word';

    public const OTHER = 'other';

    /** pdf / image / word / other, from the file's extension. */
    public static function kind(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => self::PDF,
            'jpg', 'jpeg', 'png' => self::IMAGE,
            'doc', 'docx' => self::WORD,
            default => self::OTHER,
        };
    }

    /** The Content-Type to serve an inline preview with, or null if it can't be previewed. */
    public static function inlineMime(string $filename): ?string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };
    }

    /**
     * One attached file as a Documents row.
     *
     * @return array{label: string, name: string, kind: string, view: string, download: string, note: ?string}
     */
    public static function forDocument(ApplicationDocument $document): array
    {
        return [
            'label' => $document->doc_type,
            'name' => $document->original_name,
            'kind' => self::kind($document->original_name),
            'view' => route('nureen.documents.view', $document),
            'download' => $document->url(),
            'note' => null,
        ];
    }

    /**
     * A document the module generates (a letter, the GA Extension Form).
     *
     * @return array{label: string, name: string, kind: string, view: string, download: string, note: ?string}
     */
    public static function generated(string $label, string $name, string $view, string $download, ?string $note = null): array
    {
        return ['label' => $label, 'name' => $name, 'kind' => self::PDF, 'view' => $view, 'download' => $download, 'note' => $note];
    }

    /**
     * Every attached file this viewer may open, in upload order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function attachments(Application $application, ?User $viewer): array
    {
        return $application->documents
            ->sortBy('id')
            ->filter(fn (ApplicationDocument $document) => $viewer && $document->visibleTo($viewer))
            ->map(fn (ApplicationDocument $document) => self::forDocument($document))
            ->values()
            ->all();
    }
}
