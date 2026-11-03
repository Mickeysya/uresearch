<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStudentSupervisor;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Support\CandidacyDepartmentResolver;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Chloe\Support\ProgrammeType;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives every student without one a Supervisor / Programme Manager: a lecturer of the student's OWN department. Idempotent and
 * additive: a student who already has a row is left alone, nothing is ever changed or removed, so running it again fills only
 * what is missing. A research student gets a Supervisor, picked by a hash of the student's matric number so the same student
 * always gets the same person and the load spreads over the department's lecturers; a student who already submitted an appeal gets the
 * person that appeal was routed to. A coursework student gets a Programme
 * Manager picked by a hash of the programme, so everyone on one programme has the same one.
 *
 * Covers the real student accounts (study candidacies) and the demo students. --dry-run reports the counts and writes nothing.
 */
class BackfillCandidacySupervisors extends Command
{
    protected $signature = 'candidacy:backfill-supervisors {--dry-run : Report what would be filled, write nothing}';

    protected $description = 'Fill missing Supervisor / Programme Manager records (idempotent, only adds)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $skippedNoLecturer = 0;
        $lecturers = [];

        DB::beginTransaction();

        try {
            foreach (StudyCandidacy::with('student')->whereNotIn('student_id', CandidacyStudentSupervisor::whereNotNull('student_id')->select('student_id'))->orderBy('id')->get() as $candidacy) {
                $student = $candidacy->student;
                $dept = CandidacyDepartmentResolver::forCandidacy($candidacy);
                $this->fill($rows, $skippedNoLecturer, $lecturers, $dept, $student?->programme, (string) ($student?->matric_no ?? $candidacy->student_id),
                    ['student_id' => $candidacy->student_id, 'is_demo' => false], 'real',
                    CandidacyAppeal::where('student_id', $candidacy->student_id)->orderByDesc('id')->value('supervisor_profile_id'));
            }

            foreach (DemoStudentProfile::withoutGlobalScopes()->whereNotIn('id', CandidacyStudentSupervisor::whereNotNull('demo_profile_id')->select('demo_profile_id'))->orderBy('id')->get() as $profile) {
                $dept = CandidacyDepartmentResolver::forProgramme($profile->programme, (string) $profile->matric_no);
                $this->fill($rows, $skippedNoLecturer, $lecturers, $dept, $profile->programme, (string) $profile->matric_no,
                    ['demo_profile_id' => $profile->id, 'is_demo' => true], 'demo',
                    CandidacyAppeal::where('demo_profile_id', $profile->id)->orderByDesc('id')->value('supervisor_profile_id'));
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->line(($dry ? '[dry run] ' : '').'Supervisor / Programme Manager records '.($dry ? 'that would be added' : 'added').': '.count($rows).' ('.collect($rows)->where('who', 'real')->count().' real student(s), '.collect($rows)->where('who', 'demo')->count().' demo student(s)).');
        $byDept = collect($rows)->groupBy('department')->map->count()->sortKeys();
        foreach ($byDept as $name => $count) {
            $this->line(sprintf('  %-40s %d', $name, $count));
        }
        $this->line('Programme Managers (coursework): '.collect($rows)->where('kind', CandidacyStudentSupervisor::KIND_PROGRAMME_MANAGER)->count().'; Supervisors (research): '.collect($rows)->where('kind', CandidacyStudentSupervisor::KIND_SUPERVISOR)->count().'.');
        $skippedNoLecturer && $this->warn("{$skippedNoLecturer} student(s) skipped: no department or no lecturer to give them.");

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $rows @param array<int, Collection> $lecturers */
    protected function fill(array &$rows, int &$skipped, array &$lecturers, ?CandidacyDepartment $dept, ?string $programme, string $key, array $owner, string $who, ?int $routedTo = null): void
    {
        if (! $dept) {
            $skipped++;

            return;
        }

        $list = $lecturers[$dept->id] ??= CandidacyStaffDirectory::lecturers($dept);

        if ($list->isEmpty()) {
            $skipped++;

            return;
        }

        $coursework = ProgrammeType::of($programme) === ProgrammeType::COURSEWORK;
        // coursework: one Programme Manager per programme (hash of the programme); research: one Supervisor per student (hash of the matric)
        // a student who already had an appeal keeps the person it was routed to (when that person is a lecturer of the department)
        // a real login account prefers a lecturer who has a login too (the demo chain student -> supervisor@utp.edu.my keeps working)
        $pick = ($routedTo ? $list->firstWhere('id', $routedTo) : null)
            ?? ($who === 'real' ? $list->first(fn ($p) => $p->user_id !== null) : null)
            ?? $list[crc32($coursework ? mb_strtolower((string) $programme) : $key) % $list->count()];
        $kind = $coursework ? CandidacyStudentSupervisor::KIND_PROGRAMME_MANAGER : CandidacyStudentSupervisor::KIND_SUPERVISOR;

        CandidacyStudentSupervisor::create($owner + ['staff_profile_id' => $pick->id, 'kind' => $kind]);
        $rows[] = ['who' => $who, 'department' => $dept->name, 'kind' => $kind];
    }
}
