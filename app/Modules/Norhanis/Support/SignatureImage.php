<?php

namespace App\Modules\Norhanis\Support;

use Illuminate\Http\Request;

/**
 * A signature drawn (or loaded) in the browser's signature box.
 *
 * The box always posts one PNG as a data: URL, whether the person drew it or
 * picked an image, so the server has a single shape to check. It is kept as
 * base64 in the module's own table rather than as a file: it is small, it is
 * only ever printed onto a generated PDF, and nothing ever serves it back.
 */
class SignatureImage
{
    public const MAX_BYTES = 512 * 1024;

    protected const PREFIX = 'data:image/png;base64,';

    /**
     * Take the signature out of the request and return it as clean base64,
     * or null when it is missing or is not a PNG.
     *
     * The raw field is replaced with a short marker, so `required` can still
     * be validated on it and a failed validation does not flash several
     * hundred kilobytes of image into the session.
     */
    public static function capture(Request $request, string $field): ?string
    {
        $signature = self::decode($request->input($field));

        $request->merge([$field => $signature === null ? null : 'signed']);

        return $signature;
    }

    public static function decode(mixed $dataUrl): ?string
    {
        if (! is_string($dataUrl) || ! str_starts_with($dataUrl, self::PREFIX)) {
            return null;
        }

        $bytes = base64_decode(substr($dataUrl, strlen(self::PREFIX)), true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        // Reads the header only. Anything that is not really a PNG, or is
        // far larger than a signature box, is refused.
        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] > 2000 || $info[1] > 1000) {
            return null;
        }

        // Re-encoded from the decoded bytes, so what is stored is known to be
        // plain base64 and safe to place in an <img src="data:..."> later.
        return base64_encode($bytes);
    }
}
