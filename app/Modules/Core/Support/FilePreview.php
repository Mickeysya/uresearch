<?php

namespace App\Modules\Core\Support;

/**
 * What the in-app viewer (components/file-preview) can show for a file,
 * from its extension. PDFs go in an <iframe>, JPG/PNG in an <img>; anything
 * else gets "Preview not available" and a Download button -- nothing is
 * converted.
 */
final class FilePreview
{
    public const PDF = 'pdf';

    public const IMAGE = 'image';

    public const WORD = 'word';

    public const OTHER = 'other';

    public static function kind(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => self::PDF,
            'jpg', 'jpeg', 'png' => self::IMAGE,
            'doc', 'docx' => self::WORD,
            default => self::OTHER,
        };
    }

    /** The Content-Type to serve the file inline with, or null when a browser can't show it. */
    public static function inlineMime(string $filename): ?string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };
    }
}
