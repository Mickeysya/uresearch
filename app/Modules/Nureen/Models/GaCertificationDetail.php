<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaCertificationDetail extends Model
{
    protected $fillable = [
        'application_id', 'supervisor_id', 'appointment_type', 'period_start', 'period_end', 'purpose',
        'course_code', 'course_name', 'course_owner_name',
        'project_title', 'project_leader_name', 'grant_code', 'semester',
        'reference_number', 'revoked_at', 'revoked_reason', 'draft_step',
        // CGS's online form -- migration 2026_11_05_000100.
        'study_level', 'level_of_study', 'mode_of_study', 'identity_number', 'personal_email', 'declaration_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'revoked_at' => 'datetime',
            // MyKad/Passport number: encrypted at rest with the app key.
            // Shown in full only to CGS staff -- see identityFor().
            'identity_number' => 'encrypted',
            'declaration_accepted_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** The type-specific fields configured for this row's own appointment_type. */
    /**
     * The MyKad/Passport number as $viewer may see it: in full for CGS
     * staff, masked for everyone else (the supervisor, the student
     * themselves, anyone) -- "•••••• 1234".
     */
    public function identityFor(?\App\Modules\Core\Models\User $viewer): ?string
    {
        if (blank($this->identity_number)) {
            return null;
        }

        return $viewer?->isCgs() ? $this->identity_number : self::mask($this->identity_number);
    }

    /** Every character but the last four hidden. */
    public static function mask(string $value): string
    {
        $value = trim($value);
        $visible = mb_substr($value, -4);

        return str_repeat('•', max(4, mb_strlen($value) - 4)).' '.$visible;
    }

    /**
     * GA and GRA are for full-time research students (Postgraduate
     * Handbook 2025; config('nureen.ga_certification.eligible')). A warning
     * for CGS, never a block: a former GA may still need a letter.
     *
     * @return array{status: 'pass'|'fail'|'unknown', reason: string}
     */
    public function eligibilityCheck(): array
    {
        if (! $this->study_level || ! $this->mode_of_study) {
            return ['status' => 'unknown', 'reason' => 'Level and mode of study were not given (submitted before the online form).'];
        }

        $eligible = config('nureen.ga_certification.eligible');
        $options = config('nureen.ga_certification.options');
        $declared = ($options['study_level'][$this->study_level] ?? $this->study_level).' · '.($options['mode_of_study'][$this->mode_of_study] ?? $this->mode_of_study);

        return in_array($this->study_level, $eligible['study_level'], true) && in_array($this->mode_of_study, $eligible['mode_of_study'], true)
            ? ['status' => 'pass', 'reason' => "{$declared}."]
            : ['status' => 'fail', 'reason' => "{$declared}: GA and GRA are for full-time research students. A former GA may still legitimately need a letter; check before approving."];
    }

    /** "Master · Research · Full-time ODL", from config's option lists. */
    public function studySummary(): ?string
    {
        $options = config('nureen.ga_certification.options');

        return collect([
            $options['level_of_study'][$this->level_of_study] ?? null,
            $options['study_level'][$this->study_level] ?? null,
            $options['mode_of_study'][$this->mode_of_study] ?? null,
        ])->filter()->implode(' · ') ?: null;
    }

    public function typeFields(): array
    {
        return config("nureen.ga_certification.fields.{$this->appointment_type}", []);
    }
}
