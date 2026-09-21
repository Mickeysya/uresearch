<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An approver's signature image, stamped onto the Confirmation of
 * Correction to Thesis when they approve.
 */
class HardboundSignature extends Model
{
    /** Where signature images live on the private disk. */
    public const DIRECTORY = 'hardbound-signatures';

    public const ALLOWED = ['png', 'jpg', 'jpeg'];

    public const MAX_KB = 1024;

    protected $fillable = ['user_id', 'path', 'original_name', 'mime_type'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function forUser(int $userId): ?self
    {
        return static::where('user_id', $userId)->first();
    }

    public function contents(): string
    {
        return Storage::disk('local')->get($this->path);
    }

    /** The image as a data URI, which is how Dompdf takes an inline picture. */
    public function dataUri(): string
    {
        return 'data:'.$this->mime_type.';base64,'.base64_encode($this->contents());
    }

    /** Laravel validation rules for the upload field. */
    public static function rules(): array
    {
        return ['required', 'file', 'mimes:'.implode(',', self::ALLOWED), 'max:'.self::MAX_KB];
    }
}
