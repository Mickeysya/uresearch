<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Confirmed rule: when an approver Returns an appeal, the student edits and resubmits, and it goes back to THE SAME approver who returned it:
 * not restarted from the Supervisor, not sent to a different person, even if the appeal's pointer was changed in between.
 * Kept as before: a Dean return goes to CGS first (needs_cgs_recheck); CGS is a role, not a person.
 */
class CandidacyReturnToSameApproverTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function anotherProfile(string $role, string $email): CandidacyStaffProfile
    {
        $user = $this->user($role, ['email' => $email, 'name' => 'Someone Else']);

        return CandidacyStaffProfile::create(['user_id' => $user->id, 'full_name' => 'Someone Else', 'designation' => 'Other', 'is_demo' => 0]);
    }

    public function test_a_supervisor_return_goes_back_to_that_supervisor_even_if_the_pointer_was_changed(): void
    {
        $appeal = $this->toStage('supervisor');
        $original = $appeal->supervisor_profile_id;
        $appeal = $this->service->decide($this->lecturer, $appeal, 'return', ['other'], 'Please explain.');
        $this->assertSame($original, $this->profile($this->lecturer)->id);

        $other = $this->anotherProfile(Role::SUPERVISOR, 'other-sup@test.my');
        CandidacyAppeal::whereKey($appeal->id)->update(['supervisor_profile_id' => $other->id]); // what a re-routing did

        $appeal = $this->service->resubmit($this->student, $appeal->fresh(), ['reason' => 'Explained.']);

        $this->assertSame('supervisor', $appeal->current_stage, 'not restarted from anywhere else');
        $this->assertSame($original, $appeal->supervisor_profile_id, 'back to the supervisor who returned it');
        $this->assertSame($this->lecturer->id, $appeal->fresh()->supervisorProfile->user->id, 'the person notified and allowed to act is that supervisor');
    }

    public function test_a_chair_return_goes_back_to_that_chair_even_if_the_pointer_was_changed(): void
    {
        $appeal = $this->toStage('chair');
        $supervisorPointer = $appeal->supervisor_profile_id;
        $original = $appeal->chair_profile_id;
        $appeal = $this->service->decide($this->chairUser, $appeal, 'return', ['other'], 'Please explain.');

        $other = $this->anotherProfile(Role::CHAIR, 'other-chair@test.my');
        CandidacyAppeal::whereKey($appeal->id)->update(['chair_profile_id' => $other->id]);

        $appeal = $this->service->resubmit($this->student, $appeal->fresh(), ['reason' => 'Explained.']);

        $this->assertSame('chair', $appeal->current_stage);
        $this->assertSame($original, $appeal->chair_profile_id);
        $this->assertSame($supervisorPointer, $appeal->supervisor_profile_id, 'nothing else is touched');
        $this->assertSame(2, $appeal->round);
    }

    public function test_a_returner_with_no_login_is_the_one_exception_the_pointer_is_left_alone(): void
    {
        $appeal = $this->toStage('chair');
        $appeal = $this->service->decide($this->chairUser, $appeal, 'return', ['other'], 'Please explain.');

        $real = $this->anotherProfile(Role::CHAIR, 'real-chair@test.my');
        $demo = CandidacyStaffProfile::create(['user_id' => null, 'full_name' => 'Demo Chair', 'designation' => 'Chair', 'is_demo' => 1]);
        CandidacyAppealApproval::where('candidacy_appeal_id', $appeal->id)->where('decision', 'returned')->update(['profile_id' => $demo->id]);
        CandidacyAppeal::whereKey($appeal->id)->update(['chair_profile_id' => $real->id]); // the real chair of the department

        $appeal = $this->service->resubmit($this->student, $appeal->fresh(), ['reason' => 'Explained.']);

        $this->assertSame($real->id, $appeal->chair_profile_id, 'nobody could act as the demo chair, so the real chair keeps it');
    }

    public function test_a_dean_return_still_goes_to_cgs_first_and_keeps_the_dean(): void
    {
        $appeal = $this->toStage('dean');
        $dean = $appeal->dean_profile_id;
        $appeal = $this->service->decide($this->deanUser, $appeal, 'return', ['clarification_needed']);

        $appeal = $this->service->resubmit($this->student, $appeal->fresh());

        $this->assertSame('cgs', $appeal->current_stage);
        $this->assertTrue($appeal->needs_cgs_recheck);
        $this->assertSame($dean, $appeal->dean_profile_id);
    }

    public function test_a_cgs_return_goes_back_to_the_cgs_stage_as_a_role(): void
    {
        $appeal = $this->toStage('cgs');
        $appeal = $this->service->decide($this->cgsUser, $appeal, 'return', ['documents_missing']);
        $appeal = $this->service->resubmit($this->student, $appeal->fresh(), ['reason' => 'Added.']);

        $this->assertSame('cgs', $appeal->current_stage);
    }
}
