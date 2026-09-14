<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentExaminer extends Model
{
    public const TYPE_INTERNAL = 'internal';

    public const TYPE_EXTERNAL = 'external';

    protected $fillable = [
        'application_id', 'examiner_type', 'examiner_name', 'examiner_institution',
        'examiner_address', 'examiner_email', 'examiner_expertise', 'letter_ref_no',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isInternal(): bool
    {
        return $this->examiner_type === self::TYPE_INTERNAL;
    }

    public function typeLabel(): string
    {
        return $this->isInternal() ? 'Internal Examiner' : 'External Examiner';
    }

    /**
     * The CGS templates file the two letters under different series --
     * PGS for external appointments, CGS for internal ones.
     */
    public function refSeries(): string
    {
        return $this->isInternal() ? 'CGS' : 'PGS';
    }
}
