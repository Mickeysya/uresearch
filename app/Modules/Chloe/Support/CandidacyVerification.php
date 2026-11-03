<?php

namespace App\Modules\Chloe\Support;

use Carbon\CarbonInterface;

/**
 * The verification code printed on a digital stamp and in the memo's approval record: the first 10 characters
 * (upper case) of an HMAC-SHA256 over appeal number, stage, profile id and decision time, keyed with the
 * application key. The key is read here and never printed or logged.
 */
final class CandidacyVerification
{
    public static function code(string $appealNo, string $stage, ?int $profileId, CarbonInterface $decidedAt): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7)) ?: $key;
        }

        $message = implode('|', [$appealNo, $stage, (string) $profileId, $decidedAt->format('Y-m-d H:i:s')]);

        return strtoupper(substr(hash_hmac('sha256', $message, $key), 0, 10));
    }
}
