<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\ReVivaDetail;
use App\Modules\Hani\Models\VivaDetail;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a student's corrected thesis is due for the next re-viva round, and
 * which viva the round re-examines.
 *
 *   round 1    the category-4 viva's window (viva_details, set by VivaResult)
 *   round N+1  from the previous round's level-4 outcome date
 *              (hani.reviva.next_round_window_months)
 *   legacy     no viva result in UResearch: from a viva result date the
 *              Senior Executive enters, with the viva's own window months
 *
 * A thesis logged outside the window is warned about, never refused: CGS
 * may have agreed an extension on paper.
 */
final class ReVivaWindow
{
    public const VIVA = 'viva';

    public const NEXT_ROUND = 'next_round';

    public const LEGACY = 'legacy';

    public function __construct(
        public readonly string $source,
        public readonly ?VivaDetail $viva,
        public readonly ?CarbonInterface $opens,
        public readonly ?CarbonInterface $closes,
    ) {}

    /**
     * The window for the student's next round, or null when it needs a viva
     * result date from the Senior Executive (legacy).
     */
    public static function for(User $student, ?ReVivaDetail $latest): ?self
    {
        if ($latest && $latest->requiresLoopBack()) {
            $from = Carbon::parse($latest->outcome_recorded_at ?? $latest->updated_at)->startOfDay();
            [$opens, $closes] = array_map('intval', (array) config('hani.reviva.next_round_window_months', [0, 6]));

            return new self(
                self::NEXT_ROUND,
                $latest->viva_application_id ? VivaDetail::where('application_id', $latest->viva_application_id)->first() : null,
                $from->copy()->addMonthsNoOverflow($opens),
                $from->copy()->addMonthsNoOverflow($closes),
            );
        }

        $viva = self::categoryFourViva($student);

        return $viva ? new self(self::VIVA, $viva, $viva->resubmission_opens_at, $viva->resubmission_closes_at) : null;
    }

    /** Legacy: no viva result in UResearch, so the window runs from the date the Senior Executive gives. */
    public static function fromResultDate(CarbonInterface $resultDate): self
    {
        $date = Carbon::parse($resultDate)->startOfDay();

        return new self(
            self::LEGACY,
            null,
            $date->copy()->addMonthsNoOverflow((int) config('hani.result.resubmission_opens_months', 6)),
            $date->copy()->addMonthsNoOverflow((int) config('hani.result.resubmission_closes_months', 12)),
        );
    }

    /** The student's latest viva with a category-4 (re-examination) result. */
    public static function categoryFourViva(User $student): ?VivaDetail
    {
        return VivaDetail::whereHas('application', fn ($q) => $q->where('module_type', VivaProgress::NOTICE)->where('student_id', $student->id))
            ->where('result_category', VivaDetail::REEXAMINATION)
            ->latest('id')
            ->first();
    }

    public function contains(CarbonInterface $day): bool
    {
        return $this->opens && $this->closes && $day->copy()->startOfDay()->betweenIncluded($this->opens, $this->closes);
    }

    /** Null inside the window; otherwise the warning to show. */
    public function warningFor(CarbonInterface $day): ?string
    {
        if (! $this->opens || ! $this->closes || $this->contains($day)) {
            return null;
        }

        return $day->lt($this->opens)
            ? 'Logged before the resubmission window opens ('.$this->label().').'
            : 'Logged after the resubmission window closed ('.$this->label().').';
    }

    public function label(): string
    {
        return $this->opens && $this->closes
            ? $this->opens->format('j M Y').' to '.$this->closes->format('j M Y')
            : 'no window';
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::VIVA => 'From the viva result',
            self::NEXT_ROUND => 'From the previous round\'s level 4 outcome',
            default => 'From the viva result date entered by CGS',
        };
    }
}
