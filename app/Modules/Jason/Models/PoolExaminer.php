<?php

namespace App\Modules\Jason\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An examiner the Chair can pick for a panel. Nominations copy the row's
 * fields into appointment_examiners rather than referencing it, so the
 * letters say what the examiner's details were on the day.
 */
class PoolExaminer extends Model
{
    protected $table = 'appointment_examiner_pool';

    protected $fillable = [
        'examiner_type', 'name', 'institution', 'address', 'email', 'expertise', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isInternal(): bool
    {
        return $this->examiner_type === AppointmentExaminer::TYPE_INTERNAL;
    }

    public function typeLabel(): string
    {
        return $this->isInternal() ? 'Internal Examiner' : 'External Examiner';
    }

    /**
     * The fields a nomination copies across, keyed as appointment_examiners
     * expects them.
     *
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'examiner_type' => $this->examiner_type,
            'examiner_name' => $this->name,
            'examiner_institution' => $this->institution,
            'examiner_address' => $this->address,
            'examiner_email' => $this->email,
            'examiner_expertise' => $this->expertise,
        ];
    }
}
