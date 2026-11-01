<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealEvent;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Chloe\Support\CandidacyVerification;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Phase 1: the data model's own rules -- enforced by the DATABASE where it can be (CHECK constraints, generated
 * unique keys), not only by the service that will sit on top of it.
 */
class CandidacyAppealDataModelTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function candidacy(User $student, array $attributes = []): StudyCandidacy
    {
        return StudyCandidacy::create($attributes + [
            'student_id' => $student->id, 'programme_start_date' => '2024-01-10', 'candidacy_expiry_date' => '2027-01-10',
            'original_expiry_date' => '2027-01-10', 'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);
    }

    /** MySQL names the index/constraint in its message; SQLite reports a unique failure by column (our triggers carry the name). */
    protected function assertViolation(QueryException $e, string $name): void
    {
        $message = $e->getMessage();
        $this->assertTrue(
            str_contains($message, $name) || str_contains($message, 'UNIQUE constraint failed') || str_contains($message, 'CHECK constraint failed'),
            "Expected a database rule violation ({$name}), got: ".substr($message, 0, 160)
        );
    }

    protected function appeal(User $student, StudyCandidacy $candidacy, string $status = CandidacyAppeal::STATUS_WITH_SUPERVISOR, array $extra = []): CandidacyAppeal
    {
        return CandidacyAppeal::create($extra + [
            'student_id' => $student->id, 'study_candidacy_id' => $candidacy->id, 'status' => $status, 'requested_months' => 3,
        ]);
    }

    // ---- who may have what open ------------------------------------------------------------------------------

    public function test_a_student_can_have_only_one_open_appeal_at_a_time(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_WITH_CHAIR, ['sequence_no' => 1, 'appeal_no' => 'SCA-1-01']);

        foreach ([CandidacyAppeal::STATUS_WITH_SUPERVISOR, CandidacyAppeal::STATUS_RETURNED, CandidacyAppeal::STATUS_WITH_DEAN] as $status) {
            try {
                $this->appeal($student, $candidacy, $status);
                $this->fail("a second open appeal ({$status}) was accepted");
            } catch (QueryException $e) {
                $this->assertViolation($e, 'cand_appeals_one_open');
            }
        }
    }

    public function test_decided_appeals_do_not_count_as_open_and_a_draft_may_sit_beside_an_open_one(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);

        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_APPROVED, ['sequence_no' => 1, 'appeal_no' => 'SCA-1-01']);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_CLOSED_EXPIRED, ['sequence_no' => 2, 'appeal_no' => 'SCA-1-02']);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_WITH_SUPERVISOR, ['sequence_no' => 3, 'appeal_no' => 'SCA-1-03']);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_DRAFT);

        $this->assertSame(4, CandidacyAppeal::count());
        $this->assertFalse(CandidacyAppeal::where('status', 'draft')->first()->isOpen());
    }

    public function test_a_student_can_have_only_one_draft(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_DRAFT);

        $this->expectException(QueryException::class);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_DRAFT);
    }

    public function test_two_students_can_each_have_an_open_appeal_and_a_draft(): void
    {
        $a = $this->student('22001001', 'a@test.my');
        $b = $this->student('22001002', 'b@test.my');

        foreach ([$a, $b] as $student) {
            $candidacy = $this->candidacy($student);
            $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_WITH_SUPERVISOR, ['sequence_no' => 1, 'appeal_no' => "SCA-{$student->id}-01"]);
            $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_DRAFT);
        }

        $this->assertSame(4, CandidacyAppeal::count());
    }

    public function test_the_sequence_number_is_unique_per_student_and_never_above_twelve(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_APPROVED, ['sequence_no' => 1, 'appeal_no' => 'SCA-1-01']);

        try {
            $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_APPROVED, ['sequence_no' => 1, 'appeal_no' => 'SCA-1-99']);
            $this->fail('a repeated sequence number was accepted');
        } catch (QueryException $e) {
            $this->assertViolation($e, 'cand_appeals_student_seq');
        }

        $this->expectException(QueryException::class);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_APPROVED, ['sequence_no' => 13, 'appeal_no' => 'SCA-1-13']);
    }

    public function test_the_appeal_number_is_unique(): void
    {
        $a = $this->student('22001001', 'a@test.my');
        $b = $this->student('22001002', 'b@test.my');
        $this->appeal($a, $this->candidacy($a), CandidacyAppeal::STATUS_APPROVED, ['sequence_no' => 1, 'appeal_no' => 'SCA-22001001-01']);

        $this->expectException(QueryException::class);
        $this->appeal($b, $this->candidacy($b), CandidacyAppeal::STATUS_APPROVED, ['sequence_no' => 1, 'appeal_no' => 'SCA-22001001-01']);
    }

    // ---- months ----------------------------------------------------------------------------------------------

    public function test_requested_and_approved_months_must_be_between_one_and_twelve(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);

        foreach ([0, 13] as $months) {
            try {
                $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_DRAFT, ['requested_months' => $months]);
                $this->fail("requested_months {$months} was accepted");
            } catch (QueryException $e) {
                $this->assertViolation($e, 'cand_appeals_requested_check');
            }
        }

        $this->expectException(QueryException::class);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_APPROVED, ['approved_months' => 13, 'sequence_no' => 1, 'appeal_no' => 'SCA-1-01']);
    }

    public function test_the_candidacy_can_never_hold_more_than_twelve_months_of_extension(): void
    {
        $candidacy = $this->candidacy($this->student());

        $candidacy->update(['extension_months_used' => 12]);
        $this->assertSame(12, $candidacy->fresh()->extension_months_used);

        $this->expectException(QueryException::class);
        $candidacy->update(['extension_months_used' => 13]);
    }

    public function test_an_appeal_belongs_to_exactly_one_identity(): void
    {
        $student = $this->student();

        foreach ([[], ['student_id' => $student->id, 'demo_profile_id' => 1]] as $attributes) {
            try {
                DB::table('candidacy_appeals')->insert(['status' => 'draft', 'created_at' => now(), 'updated_at' => now()] + $attributes);
                $this->fail('an appeal without exactly one identity was accepted');
            } catch (QueryException $e) {
                $this->assertTrue(str_contains($e->getMessage(), 'cand_appeals_one_identity_check') || str_contains($e->getMessage(), 'foreign key'));
            }
        }
    }

    // ---- staff -----------------------------------------------------------------------------------------------

    public function test_a_department_has_one_chair_and_at_most_one_dean_but_many_lecturers(): void
    {
        $department = CandidacyDepartment::create(['code' => 'CIS', 'name' => 'Computer & Information Sciences']);
        $profile = fn (string $name) => CandidacyStaffProfile::create(['full_name' => $name, 'designation' => 'Staff']);
        $assign = fn (CandidacyStaffProfile $p, string $role) => CandidacyStaffAssignment::create(['department_id' => $department->id, 'staff_profile_id' => $p->id, 'role' => $role]);

        $assign($profile('Lecturer One'), 'lecturer');
        $assign($profile('Lecturer Two'), 'lecturer');
        $assign($chair = $profile('Chair One'), 'chair');
        $assign($dean = $profile('Dean One'), 'dean');

        foreach (['chair', 'dean'] as $role) {
            try {
                $assign($profile("Second {$role}"), $role);
                $this->fail("a second {$role} was accepted");
            } catch (QueryException $e) {
                $this->assertViolation($e, 'cand_assign_single_holder');
            }
        }

        $this->assertCount(2, CandidacyStaffDirectory::lecturers($department));
        $this->assertSame($chair->id, CandidacyStaffDirectory::chair($department)->id);
        // a department has no Dean of its own: the single Dean is the account with the Dean role (see the next test)
    }

    public function test_the_single_dean_is_the_account_with_the_dean_role_whatever_the_department(): void
    {
        $department = CandidacyDepartment::create(['code' => 'X', 'name' => 'No Dean Dept']);
        $deanUser = $this->dean();
        $deanProfile = CandidacyStaffProfile::create(['user_id' => $deanUser->id, 'full_name' => 'Default Dean', 'designation' => 'Dean']);

        $this->assertSame($deanProfile->id, CandidacyStaffDirectory::dean($department)->id);

        config(['candidacy.default_dean_profile' => $other = CandidacyStaffProfile::create(['full_name' => 'Configured Dean', 'designation' => 'Dean'])->id]);
        $this->assertSame($other, CandidacyStaffDirectory::dean($department)->id);
    }

    // ---- append-only history ---------------------------------------------------------------------------------

    public function test_the_history_is_append_only(): void
    {
        $student = $this->student();
        $appeal = $this->appeal($student, $this->candidacy($student), CandidacyAppeal::STATUS_DRAFT);
        $event = CandidacyAppealEvent::record([
            'candidacy_appeal_id' => $appeal->id, 'actor_kind' => 'student', 'actor_name' => $student->name, 'action' => 'draft_saved',
        ]);

        foreach ([fn () => $event->update(['remark' => 'edited']), fn () => $event->delete(), function () use ($event) { $event->remark = 'x'; $event->save(); }] as $attempt) {
            try {
                $attempt();
                $this->fail('an event was changed or removed');
            } catch (LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertNull($event->fresh()->remark);
        $this->assertSame(1, CandidacyAppealEvent::count());
    }

    public function test_no_code_updates_or_deletes_events(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Chloe'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getFilename(), 'CandidacyAppealEvent.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match('/CandidacyAppealEvent::(query|where|find|whereKey|truncate)[^;]*->(update|delete|forceDelete)\(/s', $source)
                || preg_match('/table\(\s*[\'"]candidacy_appeal_events[\'"]\s*\)[^;]*->(update|delete|truncate)\(/s', $source)
                || preg_match('/\$event->(update|delete)\(/', $source)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders);
    }

    // ---- the StudyCandidacy helpers --------------------------------------------------------------------------

    public function test_the_helpers_read_the_new_tables(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);

        $this->assertTrue($candidacy->canAppeal());
        $this->assertFalse($candidacy->hasOpenAppeal());
        $this->assertSame(12, $candidacy->remainingAppealMonths());

        $appeal = $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_WITH_CHAIR, ['sequence_no' => 1, 'appeal_no' => 'SCA-1-01']);
        $this->assertTrue($candidacy->fresh()->hasOpenAppeal());

        $appeal->update(['status' => CandidacyAppeal::STATUS_APPROVED, 'approved_months' => 5]);
        $candidacy->update(['extension_months_used' => 5]);
        $this->assertFalse($candidacy->fresh()->hasOpenAppeal());
        $this->assertSame(7, $candidacy->fresh()->remainingAppealMonths());
    }

    public function test_the_allowance_is_never_below_what_the_legacy_counter_says(): void
    {
        $candidacy = $this->candidacy($this->student(), ['cumulative_extension_months' => 6, 'extension_months_used' => 0]);

        $this->assertSame(6, $candidacy->allowanceUsed());
        $this->assertSame(6, $candidacy->remainingAppealMonths());
    }

    public function test_a_full_allowance_blocks_a_new_appeal(): void
    {
        $candidacy = $this->candidacy($this->student(), ['extension_months_used' => 12]);

        $this->assertSame(0, $candidacy->remainingAppealMonths());
        $this->assertFalse($candidacy->canAppeal());
    }

    public function test_a_final_rejection_blocks_new_appeals_forever(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_REJECTED, ['sequence_no' => 1, 'appeal_no' => 'SCA-1-01', 'final_outcome' => CandidacyAppeal::OUTCOME_REJECTED_FINAL]);

        $this->assertTrue($candidacy->fresh()->hasFinalRejection());
        $this->assertFalse($candidacy->fresh()->canAppeal());
    }

    public function test_a_legacy_rejection_still_blocks(): void
    {
        $candidacy = $this->candidacy($this->student(), ['last_rejection_at' => now()]);

        $this->assertTrue($candidacy->hasFinalRejection());
        $this->assertFalse($candidacy->canAppeal());
    }

    public function test_a_closed_expired_appeal_is_not_a_final_rejection(): void
    {
        $student = $this->student();
        $candidacy = $this->candidacy($student);
        $this->appeal($student, $candidacy, CandidacyAppeal::STATUS_CLOSED_EXPIRED, ['sequence_no' => 1, 'appeal_no' => 'SCA-1-01', 'final_outcome' => CandidacyAppeal::OUTCOME_CLOSED_EXPIRED]);

        $this->assertFalse($candidacy->fresh()->hasFinalRejection());
    }

    // ---- verification code -----------------------------------------------------------------------------------

    public function test_the_verification_code_is_ten_upper_case_characters_and_depends_on_every_input(): void
    {
        $at = Carbon::parse('2026-10-11 17:19:08');
        $code = CandidacyVerification::code('SCA-22001001-01', 'dean', 7, $at);

        $this->assertMatchesRegularExpression('/^[0-9A-F]{10}$/', $code);
        $this->assertSame($code, CandidacyVerification::code('SCA-22001001-01', 'dean', 7, $at));
        $this->assertNotSame($code, CandidacyVerification::code('SCA-22001001-02', 'dean', 7, $at));
        $this->assertNotSame($code, CandidacyVerification::code('SCA-22001001-01', 'chair', 7, $at));
        $this->assertNotSame($code, CandidacyVerification::code('SCA-22001001-01', 'dean', 8, $at));
        $this->assertNotSame($code, CandidacyVerification::code('SCA-22001001-01', 'dean', 7, $at->copy()->addSecond()));
        $this->assertStringNotContainsString(substr((string) config('app.key'), 7, 12), $code);
    }

    public function test_the_verification_code_depends_on_the_application_key(): void
    {
        $at = Carbon::parse('2026-10-11 17:19:08');
        $code = CandidacyVerification::code('SCA-1-01', 'dean', 1, $at);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->assertNotSame($code, CandidacyVerification::code('SCA-1-01', 'dean', 1, $at));
    }
}
