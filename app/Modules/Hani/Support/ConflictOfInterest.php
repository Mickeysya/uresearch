<?php

namespace App\Modules\Hani\Support;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerPublication;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundDetail;

/**
 * The conflict-of-interest pre-check, from the declaration examiners sign on
 * UTP/CGS/026.
 *
 *   BLOCK    the examiner is the candidate's supervisor or co-supervisor
 *   warning  co-authored a publication with the candidate or the supervisor
 *   warning  an external examiner currently holding a UTP appointment
 *            (adjunct, visiting, industrial advisory panel)
 *   warning  an external examiner who left UTP (staff or student) less than
 *            config('hani.coi.former_utp_years') years ago
 *   warning  an "external" examiner whose institution is UTP
 *
 * A pre-check, not the declaration: it can only see what the pool records,
 * so the examiner's own UTP/CGS/026 is still collected with the appointment
 * letter. Every flag names its evidence (the publication, the date, the
 * appointment), so nobody has to take a red badge on trust.
 *
 * Only the BLOCK stops anything: ExaminerNominationController::store() refuses
 * the seat. Warnings are shown and the people in the chain decide.
 */
class ConflictOfInterest
{
    public const BLOCK = 'block';

    public const WARNING = 'warning';

    public const NOTE = 'Pre-check only: the examiner\'s own declaration (UTP/CGS/026) is still collected with the appointment letter.';

    /** @var array<int, array{supervisor: ?User, co: array{name: ?string, email: ?string}}> per student, for one request */
    protected array $people = [];

    public function __construct(protected NameNormaliser $names) {}

    /**
     * Each flag's `message` is the full sentence (exports, block errors,
     * the DAC PDF). A long one also carries a `summary` for the pill on
     * screen, with the message behind "Show details" (_coi partial).
     *
     * @return array<int, array{level: string, rule: string, message: string, summary?: string}>
     */
    public function flagsFor(Examiner $examiner, User $student): array
    {
        ['supervisor' => $supervisor, 'co' => $co] = $this->peopleFor($student);
        $flags = [];

        if ($supervisor && $this->samePerson($examiner, $supervisor->name, $supervisor->email)) {
            $flags[] = $this->flag(self::BLOCK, 'supervisor', "{$examiner->name} is {$student->name}'s supervisor and cannot examine them.");
        }

        if (($co['name'] || $co['email']) && $this->samePerson($examiner, $co['name'], $co['email'])) {
            $flags[] = $this->flag(self::BLOCK, 'co_supervisor', "{$examiner->name} is {$student->name}'s co-supervisor and cannot examine them.");
        }

        foreach ($this->coauthorships($examiner, array_filter([
            'the candidate' => $student->name,
            'the supervisor' => $supervisor?->name,
            'the co-supervisor' => $co['name'],
        ])) as $message => $summary) {
            $flags[] = $this->flag(self::WARNING, 'coauthor', $message) + ['summary' => $summary];
        }

        if ($examiner->isExternal()) {
            if (filled($examiner->current_utp_appointment)) {
                $flags[] = $this->flag(self::WARNING, 'utp_appointment',
                    "Currently appointed by UTP ({$examiner->current_utp_appointment}), so not independent of UTP.");
            }

            $years = (int) config('hani.coi.former_utp_years', 5);

            if ($examiner->former_utp_until && $examiner->former_utp_until->gt(now()->subYears($years))) {
                $flags[] = $this->flag(self::WARNING, 'former_utp',
                    'Left UTP on '.$examiner->former_utp_until->format('j M Y').", less than {$years} years ago.");
            }

            if ($this->isUtp($examiner->institution)) {
                $flags[] = $this->flag(self::WARNING, 'utp_institution',
                    "Listed as external, but the institution is UTP ({$examiner->institution}).");
            }
        }

        return $flags;
    }

    /** True when any flag blocks the nomination. */
    public function blocks(array $flags): bool
    {
        return collect($flags)->contains('level', self::BLOCK);
    }

    /** @return array{supervisor: ?User, co: array{name: ?string, email: ?string}} */
    protected function peopleFor(User $student): array
    {
        if (isset($this->people[$student->id])) {
            return $this->people[$student->id];
        }

        // The co-supervisor is recorded on the viva schedule and on Form #019;
        // the latest of either is the one in post.
        $viva = VivaDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->where(fn ($q) => $q->whereNotNull('co_supervisor_email')->orWhereNotNull('co_supervisor_name'))
            ->latest('id')->first();
        $softbound = VivaSoftboundDetail::whereHas('application', fn ($q) => $q->where('student_id', $student->id))
            ->whereNotNull('co_supervisor_email')
            ->latest('id')->first();

        return $this->people[$student->id] = [
            'supervisor' => $student->supervisor_id ? User::find($student->supervisor_id) : null,
            'co' => [
                'name' => $viva?->co_supervisor_name,
                'email' => $viva?->co_supervisor_email ?? $softbound?->co_supervisor_email,
            ],
        ];
    }

    /** Same email, or (without one to go on) a name NameNormaliser judges the same. */
    protected function samePerson(Examiner $examiner, ?string $name, ?string $email): bool
    {
        if (filled($email) && filled($examiner->email)) {
            if (mb_strtolower($email) === mb_strtolower($examiner->email)) {
                return true;
            }
        }

        return filled($name) && $this->names->similarity($examiner->name, $name) >= 95;
    }

    /**
     * Publications the examiner co-authored with any of $people.
     *
     * @param  array<string, string>  $people  role => name
     * @return array<string, string> full message => short summary for the pill
     */
    protected function coauthorships(Examiner $examiner, array $people): array
    {
        $messages = [];
        $examiner->loadMissing('publications');

        foreach ($examiner->publications as $publication) {
            /** @var ExaminerPublication $publication */
            foreach ($publication->coauthorList() as $coauthor) {
                // The examiner is on their own paper.
                if ($this->names->looksAlike($coauthor, $examiner->name)) {
                    continue;
                }

                foreach ($people as $role => $name) {
                    if ($this->names->looksAlike($coauthor, $name)) {
                        $message = sprintf('Co-authored "%s"%s with %s (%s).',
                            $publication->title,
                            $publication->year ? " ({$publication->year})" : '',
                            $name,
                            $role,
                        );
                        // The paper title can run to a paragraph; the pill names the relation.
                        $messages[$message] = sprintf('Co-author with %s%s',
                            str_replace('the ', '', $role),
                            $publication->year ? " ({$publication->year})" : '',
                        );
                    }
                }
            }
        }

        return $messages; // keyed by message, so a paper listed twice flags once
    }

    protected function isUtp(?string $institution): bool
    {
        $key = Departments::normalise($institution);

        return str_contains($key, 'universiti teknologi petronas') || preg_match('/\butp\b/', $key) === 1;
    }

    protected function flag(string $level, string $rule, string $message): array
    {
        return compact('level', 'rule', 'message');
    }
}
