<?php

namespace App\Modules\Jason\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An examiner the Chair can pick for a panel. Nominations copy the row's
 * fields into appointment_examiners rather than referencing it, so the
 * letters say what the examiner's details were on the day.
 */
class PoolExaminer extends Model
{
    /**
     * How long an examiner is held after an appointment is confirmed. The
     * appointment letter itself sets the rule: "The examiner can be assigned
     * one (1) assignment at one (1) time. The examiner can be appointed to
     * other assignment upon completion of 1st assignment during his/her
     * tenure." Completion is not something the portal can see yet -- nothing
     * records an examiner accepting or returning a report -- so the hold runs
     * for a fixed period from the appointment instead.
     */
    public const COOLDOWN_MONTHS = 3;

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

    /**
     * Appointments this examiner is serving, newest first. An appointment
     * counts from the moment the Dean approves it -- see
     * AppointmentExaminer::appointed_at.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(AppointmentExaminer::class, 'pool_examiner_id')
            ->whereNotNull('appointed_at')
            ->orderByDesc('appointed_at');
    }

    /**
     * When this examiner becomes free again, or null if they are free now.
     *
     * Reads `appointed_at` on the examiner's own appointment rows rather than
     * a flag kept on this one, so it cannot drift out of step with what was
     * actually approved.
     */
    public function availableFrom(): ?Carbon
    {
        $latest = $this->relationLoaded('appointments')
            ? $this->appointments->first()?->appointed_at
            : $this->appointments()->value('appointed_at');

        if (! $latest) {
            return null;
        }

        $free = Carbon::parse($latest)->addMonths(self::COOLDOWN_MONTHS);

        return $free->isFuture() ? $free : null;
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->availableFrom() === null;
    }

    /** "not available until 17 December 2026", or null when free. */
    public function unavailableLabel(): ?string
    {
        $free = $this->availableFrom();

        return $free ? 'on an appointment until '.$free->format('j M Y') : null;
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
            'pool_examiner_id' => $this->id,
            'examiner_type' => $this->examiner_type,
            'examiner_name' => $this->name,
            'examiner_institution' => $this->institution,
            'examiner_address' => $this->address,
            'examiner_email' => $this->email,
            'examiner_expertise' => $this->expertise,
        ];
    }
}
