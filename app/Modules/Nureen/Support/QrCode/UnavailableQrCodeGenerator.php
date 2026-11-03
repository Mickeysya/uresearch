<?php

namespace App\Modules\Nureen\Support\QrCode;

use App\Modules\Nureen\Support\Contracts\GeneratesQrCode;

/**
 * The default binding: no QR library is in composer.json (checked --
 * see SHARED_CHANGES.md), so this always returns null. Every caller
 * (the certificate PDF, and anywhere else that wants a QR) already
 * falls back to printing the URL as plain text when this happens --
 * see certificate_pdf.blade.php.
 *
 * The day endroid/qr-code (or equivalent) is added, swap the binding in
 * ModuleProvider::register() for a real implementation of
 * GeneratesQrCode; nothing else in this module needs to change.
 */
class UnavailableQrCodeGenerator implements GeneratesQrCode
{
    public function dataUri(string $content): ?string
    {
        return null;
    }
}
