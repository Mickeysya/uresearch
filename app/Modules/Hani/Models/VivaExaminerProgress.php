<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Support\VivaChecklist;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One main examiner's progress on one viva. Staff only: never shown to a
 * student. See the migration for why the report is not in DocumentStore.
 */
class VivaExaminerProgress extends Model
{
    protected $table = 'viva_examiner_progress';

    /**
     * The recommendation categories, the same for Master and PhD:
     * number => [short label, what it means].
     */
    public const CATEGORIES = [
        1 => ['Pass with Distinction', 'final thesis within 1 month'],
        2 => ['Minor', 'minor corrections, 1 to 3 months'],
        3 => ['Major', 'major corrections, 3 to 6 months'],
        4 => ['Re-examination', 'resubmit for re-examination within 6 to 12 months'],
        5 => ['Fail', 'fail'],
    ];

    public const SEATS = [
        'internal_main_id' => 'Internal',
        'external_main_id' => 'External',
    ];

    protected $fillable = [
        'viva_application_id', 'nomination_id', 'seat', 'examiner_id', 'superseded_at',
        'letter_sent_at', 'letter_source', 'acknowledgement', 'acknowledged_at', 'rejection_reason',
        'thesis_received_at', 'report_received_at', 'category', 'ratings',
        'report_path', 'report_original_name', 'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'superseded_at' => 'datetime',
            'letter_sent_at' => 'date',
            'acknowledged_at' => 'date',
            'thesis_received_at' => 'date',
            'report_received_at' => 'date',
            'category' => 'integer',
            'ratings' => 'array',
        ];
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Examiner::class);
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'viva_application_id');
    }

    public function nomination(): BelongsTo
    {
        return $this->belongsTo(ExaminerNomination::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public static function categoryLabel(?int $category): ?string
    {
        return $category ? $category.' ('.self::CATEGORIES[$category][0].')' : null;
    }

    public function sideLabel(): string
    {
        return self::SEATS[$this->seat] ?? $this->seat;
    }

    /** When the report is due: from receipt of the THESIS, 4 weeks (Master) or 6 (PhD). */
    public function reportDueAt(?string $level): ?CarbonInterface
    {
        $weeks = (int) config('hani.examiner_progress.report_weeks.'.($level === VivaChecklist::PHD ? 'PHD' : 'MSC'), 4);

        return $this->thesis_received_at?->copy()->addWeeks($weeks);
    }

    /** Where it stands, in a few words, for the screen and the reminders. */
    public function stage(): string
    {
        return match (true) {
            $this->report_received_at !== null => 'Report returned',
            $this->thesis_received_at !== null => 'Thesis received',
            $this->acknowledgement === 'accepted' => 'Accepted',
            $this->acknowledgement === 'rejected' => 'Rejected',
            $this->letter_sent_at !== null => 'Letter sent',
            default => 'Letter not sent yet',
        };
    }
}
