<?php

namespace App\Modules\Jason\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentDetail extends Model
{
    public const TYPE_INTERNAL = 'internal';

    public const TYPE_EXTERNAL = 'external';

    protected $fillable = [
        'application_id', 'examiner_name', 'examiner_institution', 'examiner_email', 'examiner_expertise',
        'examiner_type', 'letter_ref_no', 'candidate_degree', 'candidate_programme',
        'supervisor_name', 'thesis_title', 'letter_prepared_at',
    ];

    protected function casts(): array
    {
        return ['letter_prepared_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isPrepared(): bool
    {
        return $this->letter_prepared_at !== null;
    }

    public function isInternal(): bool
    {
        return $this->examiner_type === self::TYPE_INTERNAL;
    }
}
