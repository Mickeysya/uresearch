<?php

namespace App\Modules\Hani\Models;

use App\Modules\Core\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One candidate's examiner panel: a main and a backup on each side.
 *
 * The internal pair must come from the candidate's own department -- the rule
 * is enforced at nomination time in ExaminerNominationController::store(),
 * where the supervisor can still be told why. See the 2026_09_22 migration
 * for why the slots are four columns rather than a type on a row.
 */
class ExaminerNomination extends Model
{
    /** Slot column => what to call it on screen. The order is the order it is shown in. */
    public const SLOTS = [
        'internal_main_id' => 'Internal (main)',
        'internal_backup_id' => 'Internal (backup)',
        'external_main_id' => 'External (main)',
        'external_backup_id' => 'External (backup)',
    ];

    /** The two the panel cannot be filed without. */
    public const REQUIRED_SLOTS = ['internal_main_id', 'external_main_id'];

    protected $fillable = [
        'application_id',
        'internal_main_id', 'internal_backup_id', 'external_main_id', 'external_backup_id',
        'thesis_title', 'notes',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function internalMain(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'internal_main_id');
    }

    public function internalBackup(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'internal_backup_id');
    }

    public function externalMain(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'external_main_id');
    }

    public function externalBackup(): BelongsTo
    {
        return $this->belongsTo(Examiner::class, 'external_backup_id');
    }

    /** The relation name behind each slot column, for eager loading and lookups. */
    public static function slotRelations(): array
    {
        return ['internalMain', 'internalBackup', 'externalMain', 'externalBackup'];
    }

    /**
     * Every examiner actually on this panel, in slot order, with the empty
     * slots dropped. Callers that treat the panel as a set -- tying examiners
     * up, starting their cooling-off, conflict detection -- use this instead
     * of naming four relations each time and forgetting one.
     *
     * @return Collection<int, Examiner>
     */
    public function examiners(): Collection
    {
        return (new Collection([
            $this->internalMain,
            $this->internalBackup,
            $this->externalMain,
            $this->externalBackup,
        ]))->filter()->values();
    }

    /** Slot label => examiner, empty slots included, for a screen that shows the panel. */
    public function panel(): array
    {
        return array_combine(
            array_values(self::SLOTS),
            [$this->internalMain, $this->internalBackup, $this->externalMain, $this->externalBackup]
        );
    }

    /**
     * Panels whose evaluation is not over. It is over once the mains were
     * examined (PanelSeats::complete(), the gap starts) or freed without
     * one (a closed re-examination window). Read from the panel's own seat
     * history, not from the examiners' assigned_until: an examiner tied up
     * by a LATER panel must not reopen one they already examined on.
     */
    public function scopeEvaluationOpen(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('examiner_seat_history')
            ->whereColumn('examiner_seat_history.nomination_id', 'examiner_nominations.id')
            ->where(fn ($e) => $e->where('event', 'examined')
                ->orWhere(fn ($f) => $f->where('event', 'freed')->whereIn('seat', ['internal_main_id', 'external_main_id']))));
    }
}
