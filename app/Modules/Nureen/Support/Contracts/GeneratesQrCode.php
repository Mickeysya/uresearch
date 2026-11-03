<?php

namespace App\Modules\Nureen\Support\Contracts;

/**
 * Turns a URL into a scannable image for the certificate PDF. Returns null
 * when no QR library is available -- see
 * Support\QrCode\UnavailableQrCodeGenerator, bound by default, and
 * SHARED_CHANGES.md for the composer.json line a real implementation
 * needs. Callers must treat null as "print the URL as text instead", not
 * as an error.
 */
interface GeneratesQrCode
{
    /** A `data:image/...` URI Dompdf (or a browser) can embed directly, or null. */
    public function dataUri(string $content): ?string;
}
