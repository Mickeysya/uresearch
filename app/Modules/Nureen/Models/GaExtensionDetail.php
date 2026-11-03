<?php

namespace App\Modules\Nureen\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Workflows\GaExtensionWorkflow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaExtensionDetail extends Model
{
    protected $fillable = [
        'application_id', 'supervisor_id', 'current_end_date', 'requested_new_end_date', 'reason_for_extension',
        'additional_info', 'final_approver_snapshot', 'draft_step',
        // The GA Extension Form's Section B -- config('nureen.ga_extension.applicant_fields').
        'mode_of_study', 'study_type', 'admission_date', 'candidature_period', 'level_of_study', 'contact_no', 'contact_email',
        'bank_name', 'bank_account_no', 'identity_number', 'holds_visa', 'visa_expiry_date',
        'publications', 'declaration_accepted_at',
        'cgs_remarks', 'cgs_remarks_by_id', 'cgs_remarks_at',
    ];

    /** Section B fields masked for everyone but the CGS stage. */
    public const SENSITIVE = ['bank_account_no', 'identity_number'];

    protected function casts(): array
    {
        return [
            'current_end_date' => 'date',
            'requested_new_end_date' => 'date',
            // Provisional -- see config('nureen.ga_extension.additional_fields').
            'additional_info' => 'array',
            'admission_date' => 'date',
            'visa_expiry_date' => 'date',
            'holds_visa' => 'boolean',
            'bank_account_no' => 'encrypted',
            'identity_number' => 'encrypted',
            'publications' => 'array',
            'declaration_accepted_at' => 'datetime',
            'cgs_remarks' => 'array',
            'cgs_remarks_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The supervisor this application's 'supervisor' stage is addressed to. */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function cgsRemarksBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cgs_remarks_by_id');
    }

    /**
     * Whether $viewer sees the account and IC/passport numbers in full: only
     * the role that acts at the CGS stage (cgs_verify). Everyone else --
     * the student included -- sees the last four characters.
     */
    public static function revealsSensitiveTo(?User $viewer): bool
    {
        if (! $viewer) {
            return false;
        }

        foreach (app(GaExtensionWorkflow::class)->stages() as $stage) {
            if ($stage->key === 'cgs_verify') {
                return $viewer->role === $stage->role;
            }
        }

        return false;
    }

    /** One of SENSITIVE, in full or masked for $viewer; null when not given. */
    public function sensitiveFor(string $field, ?User $viewer): ?string
    {
        $value = $this->{$field};

        if (blank($value)) {
            return null;
        }

        return self::revealsSensitiveTo($viewer) ? $value : self::mask($value);
    }

    /** Every character but the last four hidden. */
    public static function mask(string $value): string
    {
        $value = trim($value);

        return str_repeat('•', max(4, mb_strlen($value) - 4)).' '.mb_substr($value, -4);
    }

    /** A config option's label, e.g. level_of_study 'phd' => 'PhD'. */
    public function optionLabel(string $field): ?string
    {
        $value = $this->{$field};

        return config("nureen.ga_extension.applicant_fields.{$field}.options.{$value}") ?? $value;
    }

    /**
     * The new appointment starts the day after the current one ends. Null
     * until the current end date is known.
     */
    public function extensionStart(): ?CarbonInterface
    {
        return $this->current_end_date?->copy()->addDay();
    }

    /**
     * Months between $from and the day after $to, so a whole appointment
     * (1 Jan - 30 Jun) counts as exactly 6. Rounded to 0.1.
     */
    public static function monthsBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        return round($from->copy()->startOfDay()->diffInMonths($to->copy()->startOfDay()->addDay()), 1);
    }

    /** The extension's length in months, or null until both dates are known. */
    public function extensionMonths(): ?float
    {
        return $this->current_end_date && $this->requested_new_end_date
            ? self::monthsBetween($this->extensionStart(), $this->requested_new_end_date)
            : null;
    }

    /** "16 Jan 2027 – 15 Jul 2027 (6 months)" -- Section B's "Extension of GA duration". */
    public function extensionSummary(): ?string
    {
        $months = $this->extensionMonths();

        if ($months === null) {
            return null;
        }

        $count = fmod($months, 1.0) === 0.0 ? (int) $months : $months;

        return $this->extensionStart()->format('j M Y').' – '.$this->requested_new_end_date->format('j M Y')
            .' ('.$count.' '.($count == 1 ? 'month' : 'months').')';
    }

    /**
     * Section C rows, empty ones dropped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicationRows(): array
    {
        return array_values(array_filter($this->publications ?? [], fn ($row) => is_array($row) && array_filter($row, 'filled') !== []));
    }

    /** "Authors (2025). Title. Venue, 12, 1-10. doi:..." for one Section C row. */
    public static function citation(array $row): string
    {
        $head = trim(($row['authors'] ?? '').(filled($row['year'] ?? null) ? ' ('.$row['year'].')' : ''));
        $tail = implode(', ', array_filter([
            $row['venue'] ?? null,
            filled($row['volume'] ?? null) ? 'Vol. '.$row['volume'] : null,
            filled($row['pages'] ?? null) ? 'pp. '.$row['pages'] : null,
        ], 'filled'));

        $status = config('nureen.ga_extension.publications.statuses')[$row['status'] ?? ''] ?? null;

        return implode('. ', array_filter([
            $head,
            ($row['title'] ?? null).($status ? " ({$status})" : ''),
            $tail,
            filled($row['doi'] ?? null) ? 'DOI: '.$row['doi'] : null,
        ], 'filled'));
    }

    /** "Scopus, Q1" for one Section C row. */
    public static function databaseAndQuartile(array $row): string
    {
        $databases = config('nureen.ga_extension.publications.databases');

        return implode(', ', array_filter([
            $databases[$row['database'] ?? ''] ?? null,
            $row['quartile'] ?? null,
        ], 'filled'));
    }
}
