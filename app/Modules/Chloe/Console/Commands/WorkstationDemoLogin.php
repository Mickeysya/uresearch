<?php

namespace App\Modules\Chloe\Console\Commands;

use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\WorkstationHistory;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * One dedicated test login, "Chloe Ching", for exercising the Workstation
 * and Study Candidacy student screens by hand -- the two real test
 * accounts (student@utp.edu.my, student2@utp.edu.my) are DatabaseSeeder's
 * and are shared demo data; this one is this command's alone, created and
 * reset only here, never touched by any seeder.
 *
 * Idempotent by (name, role) rather than by email, because the email is
 * derived FROM the generated student ID -- there is nothing else stable to
 * look the account up by on the first run. Once created, the student ID
 * and email never change; re-running this command only resets Workstation
 * state and applies whichever of --gender/--expiry-date were actually
 * passed.
 *
 * Gender is still read-only from every Workstation screen (see the module
 * README) -- this command is the one place allowed to write to
 * student_genders, same as DatabaseSeeder's demo assignments.
 */
class WorkstationDemoLogin extends Command
{
    protected $signature = 'workstation:demo-login {--gender=Female : Male or Female} {--expiry-date= : Y-m-d, sets/changes the Study Candidacy expiry date}';

    protected $description = 'Create (or reset) the one dedicated "Chloe Ching" test student login for Workstation and Study Candidacy testing';

    protected const NAME = 'Chloe Ching';

    protected const PASSWORD = 'password';

    protected const PROGRAMME = 'MSc Full-Time';

    public function handle(WorkstationAllocator $allocator): int
    {
        if (app()->environment('production')) {
            $this->error('workstation:demo-login refuses to run in production.');

            return self::FAILURE;
        }

        $gender = $this->resolveGender();

        if (! $gender) {
            $this->error('Invalid --gender: use Male or Female.');

            return self::FAILURE;
        }

        $expiryDate = $this->resolveExpiryDate();

        if ($expiryDate === false) {
            $this->error('Invalid --expiry-date: use Y-m-d, e.g. 2027-08-26.');

            return self::FAILURE;
        }

        $user = User::where('role', Role::STUDENT)->where('name', self::NAME)->first();

        if (! $user) {
            try {
                $user = $this->createAccount($expiryDate ?: now()->addMonths(10)->toDateString());
            } catch (\RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("Created {$user->email}.");
        } else {
            $this->resetWorkstationState($user, $allocator);

            if ($expiryDate) {
                StudyCandidacy::where('student_id', $user->id)->update(['candidacy_expiry_date' => $expiryDate]);
            }

            $this->info("Reset Workstation state for {$user->email}.");
        }

        StudentGender::updateOrCreate(['student_id' => $user->id], ['gender' => $gender]);

        $this->printSummary($user, $gender);

        return self::SUCCESS;
    }

    protected function resolveGender(): ?string
    {
        return match (strtolower((string) $this->option('gender'))) {
            'female' => WorkstationLocation::GENDER_FEMALE,
            'male' => WorkstationLocation::GENDER_MALE,
            default => null,
        };
    }

    /** @return string|false|null null = not passed, false = invalid, string = the date */
    protected function resolveExpiryDate(): string|false|null
    {
        $raw = $this->option('expiry-date');

        if (! $raw) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->toDateString();
        } catch (\Exception) {
            return false;
        }
    }

    protected function createAccount(string $expiryDate): User
    {
        $matricNo = $this->generateUniqueMatricNo();
        $email = 'chloe_'.$matricNo.'@utp.edu.my';

        $user = User::create([
            'name' => self::NAME,
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'role' => Role::STUDENT,
            'matric_no' => $matricNo,
            'programme' => self::PROGRAMME,
        ]);

        StudyCandidacy::create([
            'student_id' => $user->id,
            // A year-and-change into a postgraduate candidacy -- plausible,
            // and irrelevant to any job's logic either way; every reminder/
            // dismissal decision in this module reads candidacy_expiry_date,
            // never this field. See StudyCandidacy's own class doc.
            'programme_start_date' => now()->subMonths(14),
            'candidacy_expiry_date' => $expiryDate,
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);

        return $user;
    }

    /**
     * YY00xxxx, xxxx random 0001-9999, checked against users.matric_no --
     * the one and only place a student ID is stored as an identity (not a
     * denormalised snapshot copy; see the command's own report for the two
     * tables that keep one of those, and why neither needs its own check).
     * That column already carries a database-level UNIQUE constraint, so
     * this loop is belt-and-suspenders: a clear retry instead of a raw
     * constraint-violation exception on the one-in-ten-thousand collision.
     * Bounded, not a bare do/while -- the whole YY00xxxx space for one
     * year is only 9999 values, so a bare loop against an (admittedly
     * pathological) fully-occupied year would spin forever instead of
     * failing.
     *
     * @throws \RuntimeException if every id in this year's space is taken
     */
    protected function generateUniqueMatricNo(): string
    {
        $year = now()->format('y');

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $candidate = $year.'00'.sprintf('%04d', random_int(1, 9999));

            if (! User::where('matric_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Could not find a free student ID in the {$year}00xxxx space after 200 attempts.");
    }

    /**
     * Back to a clean slate -- no confirmed seat, no pending selection, no
     * locker key -- entirely through WorkstationAllocator's own methods and
     * LockerKey::markReturned(), so every change still lands one History
     * row each; nothing here ever deletes one. actorType System suppresses
     * the student-facing release email (see releaseSeat()'s own doc), which
     * is right for a test reset -- Mailpit should not fill up with "you
     * lost your seat" every time this command runs.
     */
    protected function resetWorkstationState(User $user, WorkstationAllocator $allocator): void
    {
        $activeRequest = WorkstationRequest::where('student_id', $user->id)
            ->where('status', WorkstationRequest::STATUS_CONFIRMED)
            ->latest('requested_at')
            ->first();

        if ($activeRequest) {
            $allocator->releaseSeat(
                $activeRequest,
                WorkstationHistory::ACTOR_SYSTEM,
                null,
                'Reset via workstation:demo-login',
                isAutomaticRelease: true,
            );
        }

        // Whatever the release above just turned a Collected key into --
        // Return Pending has no "it's fine, leave it" case for a reset.
        $returnPending = LockerKey::where('student_id', $user->id)
            ->where('status', LockerKey::STATUS_RETURN_PENDING)
            ->latest('requested_at')
            ->first();

        $returnPending?->markReturned(WorkstationHistory::ACTOR_SYSTEM, 'System');

        // Defensive only -- normally unreachable, since a Requested/
        // Collected key can only exist tied to the confirmed request just
        // released above, which already resolved it via
        // handleKeyOnRelease(). Covers a key row left stranded by a
        // request that was never confirmed in the first place.
        $stillOpen = LockerKey::where('student_id', $user->id)
            ->whereIn('status', [LockerKey::STATUS_REQUESTED, LockerKey::STATUS_COLLECTED])
            ->latest('requested_at')
            ->first();

        if ($stillOpen) {
            $seat = $stillOpen->workstationRequest?->workstation;
            $before = $stillOpen->status;

            $stillOpen->update(['status' => LockerKey::STATUS_CANCELLED]);

            if ($seat) {
                WorkstationHistory::record(
                    seat: $seat,
                    actorType: WorkstationHistory::ACTOR_SYSTEM,
                    actorName: 'System',
                    action: WorkstationHistory::ACTION_KEY_CANCELLED,
                    reason: 'Reset via workstation:demo-login',
                    beforeState: $before,
                    afterState: LockerKey::STATUS_CANCELLED,
                    student: $user,
                );
            }
        }
    }

    protected function printSummary(User $user, string $gender): void
    {
        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Full name', $user->name],
            ['Student ID', $user->matric_no],
            ['Email', $user->email],
            ['Password', self::PASSWORD],
            ['Gender', ucfirst($gender)],
        ]);
    }
}
