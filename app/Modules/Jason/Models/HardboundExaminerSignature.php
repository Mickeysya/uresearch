<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An appointed examiner's signature on one Confirmation of Correction.
 *
 * The Supervisor's and the Chairman's signatures live in
 * `hardbound_signatures`, keyed on their user account and uploaded once for
 * every form they will ever sign. An examiner has no account, signs one
 * form, and reaches it through an emailed link -- so this is per
 * application, not per person.
 */
class HardboundExaminerSignature extends Model
{
    /** Where examiner signature images live on the private disk. */
    public const DIRECTORY = 'hardbound-examiner-signatures';

    public const ALLOWED = ['png', 'jpg', 'jpeg'];

    public const MAX_KB = 1024;

    /** How long an emailed signing link stays valid. */
    public const LINK_DAYS = 30;

    protected $fillable = [
        'application_id', 'appointment_examiner_id', 'path', 'original_name', 'mime_type', 'signed_at',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The appointed examiner who signed; carries their name and email. */
    public function examiner(): BelongsTo
    {
        return $this->belongsTo(AppointmentExaminer::class, 'appointment_examiner_id');
    }

    public static function forApplication(int $applicationId): ?self
    {
        return static::where('application_id', $applicationId)->first();
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

    /** @return array<int, string> */
    public static function rules(): array
    {
        return ['required', 'file', 'mimes:'.implode(',', self::ALLOWED), 'max:'.self::MAX_KB];
    }
}
