<?php

namespace App\Modules\Chloe\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidacyAppealDetail extends Model
{
    public const PHASE_WRITING = 'writing';

    public const PHASE_SIMULATION = 'simulation';

    public const PHASE_EXPERIMENT = 'experiment';

    public const PHASE_DATA_ANALYSIS = 'data_analysis';

    public const RCS_COMPLETED = 'completed';

    public const RCS_PENDING = 'pending';

    protected $fillable = [
        'application_id', 'study_candidacy_id', 'supervisor_id',
        'reason', 'requested_extension_months', 'new_expiry_date',
        'phase', 'writing_completion_percent',
        'rcs_status', 'rcs_date', 'rcs_category', 'rcs_expected_date',
        'extension_via_gsc', 'extension_via_vc',
        'disclaimer_acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'new_expiry_date' => 'date',
            'rcs_date' => 'date',
            'rcs_expected_date' => 'date',
            'extension_via_gsc' => 'boolean',
            'extension_via_vc' => 'boolean',
            'disclaimer_acknowledged_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function candidacy(): BelongsTo
    {
        return $this->belongsTo(StudyCandidacy::class, 'study_candidacy_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(CandidacyAppealPublication::class);
    }

    public static function phases(): array
    {
        return [
            self::PHASE_WRITING => 'Writing',
            self::PHASE_SIMULATION => 'Simulation',
            self::PHASE_EXPERIMENT => 'Experiment',
            self::PHASE_DATA_ANALYSIS => 'Data Analysis',
        ];
    }

    public function phaseLabel(): string
    {
        return self::phases()[$this->phase] ?? $this->phase ?? '—';
    }
}
