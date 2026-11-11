<?php

namespace App\Modules\Hani\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** One user's re-viva signature image, on the private disk. */
class ReVivaSignature extends Model
{
    protected $fillable = ['user_id', 'path'];

    public static function forUser(int $userId): ?self
    {
        return self::where('user_id', $userId)->first();
    }

    /** For Dompdf: a data URI, or null when the file is missing. */
    public function dataUri(): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists($this->path) ? 'data:'.($disk->mimeType($this->path) ?: 'image/png').';base64,'.base64_encode($disk->get($this->path)) : null;
    }
}
