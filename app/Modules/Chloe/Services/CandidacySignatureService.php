<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Approver signature images. An upload is never trusted: it is decoded with GD (proving it is a real PNG/JPEG),
 * must be at least 200x60, is resized to fit 600x200 and re-encoded as a PNG under a generated name. The file lives
 * on the private "local" disk and is only served through the policy-checked route.
 */
class CandidacySignatureService
{
    public function store(CandidacyStaffProfile $profile, UploadedFile $file, ?User $actor = null): CandidacyStaffProfile
    {
        $limits = config('candidacy.signature');

        if (! $file->isValid() || $file->getSize() > $limits['max_bytes']) {
            throw ValidationException::withMessages(['signature' => 'The signature image must be a PNG or JPG of at most 1 MB.']);
        }

        $png = $this->normalise((string) file_get_contents($file->getRealPath()), $limits);
        $path = 'candidacy/signatures/'.$profile->id.'-'.Str::random(24).'.png';
        Storage::disk('local')->put($path, $png);

        $old = $profile->signature_path;
        $profile->forceFill(['signature_path' => $path, 'signature_sha256' => hash('sha256', $png), 'signature_updated_at' => now()])->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        $this->event($profile, 'signature_uploaded', $actor);

        return $profile;
    }

    public function remove(CandidacyStaffProfile $profile, ?User $actor = null): void
    {
        if ($profile->signature_path) {
            Storage::disk('local')->delete($profile->signature_path);
        }

        $profile->forceFill(['signature_path' => null, 'signature_sha256' => null, 'signature_updated_at' => null])->save();
        $this->event($profile, 'signature_removed', $actor);
    }

    /** A COPY of the current signature for one decision: later replacing the signature never changes an existing memo. */
    public function snapshot(CandidacyStaffProfile $profile, string $folder): array
    {
        if (! $profile->signature_path || ! Storage::disk('local')->exists($profile->signature_path)) {
            return [null, null];
        }

        $bytes = Storage::disk('local')->get($profile->signature_path);
        $path = 'candidacy/signature-snapshots/'.$folder.'-'.Str::random(16).'.png';
        Storage::disk('local')->put($path, $bytes);

        return [$path, hash('sha256', $bytes)];
    }

    /** A plain drawn signature for demo staff (clearly not a real person's). */
    public function placeholder(CandidacyStaffProfile $profile): CandidacyStaffProfile
    {
        $img = imagecreatetruecolor(400, 120);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 255, 255, 127));
        imagealphablending($img, true);
        $ink = imagecolorallocate($img, 20, 20, 20);
        imagesetthickness($img, 3);
        $seed = crc32($profile->full_name);
        $x = 20;
        $y = 70;
        for ($i = 0; $i < 9; $i++) {
            $nx = $x + 30 + (($seed >> ($i * 2)) % 18);
            $ny = 30 + (($seed >> ($i * 3)) % 60);
            imageline($img, $x, $y, $nx, $ny, $ink);
            $x = $nx;
            $y = $ny;
        }
        imageline($img, 20, 100, $x, 100, $ink);

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $path = 'candidacy/signatures/'.$profile->id.'-'.Str::random(24).'.png';
        Storage::disk('local')->put($path, $png);
        $profile->forceFill(['signature_path' => $path, 'signature_sha256' => hash('sha256', $png), 'signature_updated_at' => now()])->save();

        return $profile;
    }

    /** @param array<string,int> $limits */
    protected function normalise(string $bytes, array $limits): string
    {
        $info = @getimagesizefromstring($bytes);

        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw ValidationException::withMessages(['signature' => 'The file is not a PNG or JPG image.']);
        }

        [$w, $h] = $info;

        if ($w < $limits['min_width'] || $h < $limits['min_height']) {
            throw ValidationException::withMessages(['signature' => "The signature image must be at least {$limits['min_width']} x {$limits['min_height']} pixels."]);
        }

        $src = @imagecreatefromstring($bytes);

        if (! $src) {
            throw ValidationException::withMessages(['signature' => 'The image could not be read.']);
        }

        $scale = min(1, $limits['max_width'] / $w, $limits['max_height'] / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        $ok = imagepng($dst);
        $png = (string) ob_get_clean();

        if (! $ok || $png === '') {
            throw new RuntimeException('The signature image could not be encoded.');
        }

        return $png;
    }

    protected function event(CandidacyStaffProfile $profile, string $action, ?User $actor): void
    {
        CandidacyAppealEvent::record([
            'staff_profile_id' => $profile->id,
            'actor_kind' => 'system',
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name ?? $profile->full_name,
            'actor_role' => $actor?->role,
            'action' => $action,
        ]);
    }
}
