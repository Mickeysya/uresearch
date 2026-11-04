<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Support\Collection;

/**
 * Touchpoint 2: the same examiner on more than one department's nomination.
 *
 * Departments nominate independently, so each list is clean on its own and
 * the clash only exists once the lists meet. This is the one place that
 * finds it -- the nomination form (as a warning), the Academic Executive's
 * card, the Examiner Report, Conflict Detection and the notification the
 * Senior Executive gets at cgs_compile all read it. Flags, never rejects:
 * which department finds a substitute is a human decision.
 *
 * "Active" means pending or approved: a rejected list no longer holds anyone,
 * and a draft has not been filed. Nor does a released panel whose evaluation
 * is over (Prompt 4): its mains were examined (the gap started) or freed, so
 * last year's examination is not a claim on the examiner today.
 */
class CrossDepartmentClashes
{
    /** @var Collection<int, ExaminerNomination>|null */
    protected ?Collection $active = null;

    /** @return Collection<int, ExaminerNomination> */
    public function active(): Collection
    {
        return $this->active ??= ExaminerNomination::with(array_merge(ExaminerNomination::slotRelations(), ['application.student']))
            ->whereHas('application', fn ($q) => $q->where('module_type', 'examiner_nomination')
                ->whereIn('status', [Application::STATUS_PENDING, Application::STATUS_APPROVED]))
            ->evaluationOpen()
            ->get();
    }

    /**
     * Other departments' active nominations that name this examiner.
     *
     * @return array<int, array{application_id: int, department: string, seat: string, status: string}>
     */
    public function elsewhere(int $examinerId, ?string $department, ?int $exceptApplicationId = null): array
    {
        $hits = [];

        foreach ($this->active() as $nomination) {
            $theirs = $nomination->application?->student?->department;

            if ($nomination->application_id === $exceptApplicationId || $theirs === null || $theirs === $department) {
                continue;
            }

            foreach (ExaminerNomination::SLOTS as $column => $seat) {
                if ($nomination->{$column} === $examinerId) {
                    $hits[] = [
                        'application_id' => $nomination->application_id,
                        'department' => $theirs,
                        'seat' => $seat,
                        'status' => $nomination->application->status,
                    ];
                }
            }
        }

        return $hits;
    }

    /**
     * As flags in the shape the COI partial renders, for one seat.
     *
     * @return array<int, array{level: string, rule: string, message: string}>
     */
    public function flags(int $examinerId, ?string $department, ?int $exceptApplicationId = null): array
    {
        return array_map(fn ($hit) => [
            'level' => 'warning',
            'rule' => 'clash',
            'message' => "Also nominated by {$hit['department']} (application #{$hit['application_id']}, {$hit['seat']}, {$hit['status']}).",
        ], $this->elsewhere($examinerId, $department, $exceptApplicationId));
    }

    /**
     * Every examiner named by more than one department, with the departments.
     *
     * @return Collection<int, Collection<int, string>> examiner id => departments
     */
    public function map(?Collection $nominations = null): Collection
    {
        return ($nominations ?? $this->active())
            ->flatMap(fn (ExaminerNomination $n) => $n->examiners()->map(fn ($e) => [
                'examiner_id' => $e->id,
                'department' => $n->application?->student?->department,
            ]))
            ->groupBy('examiner_id')
            ->map(fn ($rows) => $rows->pluck('department')->filter()->unique()->values())
            ->filter(fn ($departments) => $departments->count() > 1);
    }
}
