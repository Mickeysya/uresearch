<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;

/**
 * What is known about a student's mode of study (full-time, part-time,
 * full-time ODL), programme type (research, coursework) and level (MSc,
 * PhD) -- and where it came from.
 *
 * Core's users table has none of this (SHARED_CHANGES.md §16 proposes the
 * fields), so it is pieced together from what the student has declared in
 * this module, most recent first:
 *   1. their latest submitted GA/GRA Certification (all three),
 *   2. their latest submitted GA Extension (mode, type, level),
 *   3. users.programme, free text, when it says so ("PhD Part-Time").
 * Each attribute takes the first source that has it. A draft never counts:
 * it has not been declared yet.
 */
final class StudyProfile
{
    public function __construct(
        public readonly ?string $mode,
        public readonly ?string $type,
        public readonly ?string $level,
        public readonly ?string $source,
    ) {}

    public static function for(User $student): self
    {
        $declared = collect([
            self::latest(GaCertificationDetail::class, $student, fn (GaCertificationDetail $d) => [
                'mode' => $d->mode_of_study, 'type' => $d->study_level, 'level' => $d->level_of_study,
            ], 'GA/GRA Certification'),
            self::latest(GaExtensionDetail::class, $student, fn (GaExtensionDetail $d) => [
                'mode' => $d->mode_of_study, 'type' => $d->study_type, 'level' => $d->level_of_study,
            ], 'GA Extension'),
        ])->filter()->sortByDesc('at')->values();

        $fromProgramme = self::fromProgramme($student->programme);
        $sources = [...$declared->all(), ['values' => $fromProgramme, 'label' => 'programme on profile']];

        $pick = function (string $key) use ($sources): array {
            foreach ($sources as $source) {
                if (filled($source['values'][$key] ?? null)) {
                    return [$source['values'][$key], $source['label']];
                }
            }

            return [null, null];
        };

        [$mode, $modeSource] = $pick('mode');
        [$type, $typeSource] = $pick('type');
        [$level] = $pick('level');

        return new self($mode, $type, $level, $modeSource ?? $typeSource);
    }

    /** Full-time by research: true, false, or null when it is not known. */
    public function isFullTimeResearch(): ?bool
    {
        if (($this->mode !== null && $this->mode !== 'full_time') || ($this->type !== null && $this->type !== 'research')) {
            return false;
        }

        return $this->mode === 'full_time' && $this->type === 'research' ? true : null;
    }

    /** "Full-time · Research" / "Part-time · type not recorded" -- for CGS. */
    public function describe(): string
    {
        $modes = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'full_time_odl' => 'Full-time ODL'];

        return ($modes[$this->mode] ?? 'Mode not recorded').' · '.($this->type ? ucfirst($this->type) : 'type not recorded');
    }

    /**
     * Mode and level from free text such as "PhD Part-Time" or "MSc
     * Full-Time". Never the programme type: "Master of Science (...)" does
     * not say whether it is by research or coursework.
     *
     * @return array{mode: ?string, type: null, level: ?string}
     */
    public static function fromProgramme(?string $programme): array
    {
        $text = strtolower((string) $programme);

        $mode = match (true) {
            (bool) preg_match('/part[\s-]*time/', $text) => 'part_time',
            (bool) preg_match('/\bodl\b|open distance/', $text) => 'full_time_odl',
            (bool) preg_match('/full[\s-]*time/', $text) => 'full_time',
            default => null,
        };

        $level = match (true) {
            (bool) preg_match('/\bphd\b|doctor of philosophy/', $text) => 'phd',
            (bool) preg_match('/\bmsc\b|\bmaster\b/', $text) => 'msc',
            default => null,
        };

        return ['mode' => $mode, 'type' => null, 'level' => $level];
    }

    /**
     * The student's most recently submitted detail row of $model, as
     * ['values' => [...], 'label' => ..., 'at' => submitted_at].
     *
     * @param  class-string<GaCertificationDetail|GaExtensionDetail>  $model
     */
    protected static function latest(string $model, User $student, \Closure $values, string $label): ?array
    {
        $detail = $model::query()
            ->whereHas('application', fn ($q) => $q
                ->where('student_id', $student->id)
                ->where('status', '!=', Application::STATUS_DRAFT))
            ->with('application')
            ->get()
            ->sortByDesc(fn ($d) => $d->application->submitted_at)
            ->first();

        return $detail ? ['values' => $values($detail), 'label' => $label, 'at' => $detail->application->submitted_at] : null;
    }
}
