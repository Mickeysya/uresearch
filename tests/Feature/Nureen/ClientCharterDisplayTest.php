<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesUsers;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\TestCase;

/**
 * Where the Client Charter shows up: the student's Expected Decision Dates
 * page, and each stage's due date in the approver queues. Today is pinned
 * to Friday 6 Nov 2026.
 */
class ClientCharterDisplayTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-06 10:00'));
    }

    protected function gaExtension(User $student, string $stage, string $submittedAt, string $status = Application::STATUS_PENDING): Application
    {
        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_extension',
            'status' => $status,
            'current_stage' => $stage,
            'submitted_at' => Carbon::parse($submittedAt),
        ]);
        GaExtensionDetail::create([
            'application_id' => $application->id,
            'current_end_date' => '2026-12-31',
            'requested_new_end_date' => '2027-06-30',
            'reason_for_extension' => 'Test fixture for the Client Charter.',
            'final_approver_snapshot' => 'cgs',
        ]);

        return $application;
    }

    public function test_the_expected_date_appears_for_a_pending_application_and_not_a_decided_one(): void
    {
        $student = $this->student();

        // Reached the supervisor Thursday: due Tue 10, + CGS's 2 = Thu 12.
        $pending = $this->gaExtension($student, 'supervisor', '2026-11-05 09:00');
        $decided = $this->gaExtension($student, 'cgs_verify', '2026-10-01 09:00', Application::STATUS_APPROVED);

        $this->actingAs($student)
            ->get(route('nureen.expected-dates'))
            ->assertOk()
            ->assertSee('data-expected-for="'.$pending->id.'"', false)
            ->assertDontSee('data-expected-for="'.$decided->id.'"', false)
            ->assertSee('Expected decision by')
            ->assertSee('Thursday, 12 November 2026')
            // Whole chain's total, not just what's left ahead -- see
            // ClientCharterTest's own comment on the same number.
            ->assertSee('Based on the CGS Client Charter of 7 working days')
            ->assertDontSee('taking longer than the Client Charter');
    }

    public function test_the_overdue_message_appears_only_when_the_stage_is_overdue(): void
    {
        $student = $this->student();

        // Reached the supervisor Monday: 4 working days, over its 3.
        $this->gaExtension($student, 'supervisor', '2026-11-02 09:00');

        $this->actingAs($student)
            ->get(route('nureen.expected-dates'))
            ->assertOk()
            ->assertSee("This stage is taking longer than the Client Charter. You don't need to do anything; the reviewer has been reminded.", false)
            // From today, never the missed date: Fri 6 + 2 = Tue 10.
            ->assertSee('Tuesday, 10 November 2026');
    }

    public function test_a_student_sees_only_their_own_applications(): void
    {
        $mine = $this->student();
        $theirs = $this->student('22001002', 'other@test.my');
        $other = $this->gaExtension($theirs, 'supervisor', '2026-11-05 09:00');

        $this->actingAs($mine)
            ->get(route('nureen.expected-dates'))
            ->assertOk()
            ->assertDontSee('data-expected-for="'.$other->id.'"', false);

        $this->actingAs($this->cgs())
            ->get(route('nureen.expected-dates'))
            ->assertForbidden();
    }

    public function test_the_approver_queue_highlights_overdue_items_and_sorts_them_first(): void
    {
        $cgs = $this->cgs();
        $supervisor = $this->supervisor();

        // Submitted FIRST, but only reached CGS yesterday: on time.
        $onTimeStudent = $this->student('22005551', 'ontime@test.my');
        $onTime = $this->gaExtension($onTimeStudent, 'cgs_verify', '2026-10-20 09:00');
        $this->moved($onTime, $supervisor, '2026-11-05 09:00:00');

        // Submitted later, but reached CGS on Monday: 4 working days, over 2.
        $overdueStudent = $this->student('22005552', 'overdue@test.my');
        $overdue = $this->gaExtension($overdueStudent, 'cgs_verify', '2026-10-28 09:00');
        $this->moved($overdue, $supervisor, '2026-11-02 09:00:00');

        $response = $this->actingAs($cgs)->get(route('ga-extension.queue'));

        $response->assertOk()
            // Overdue first, even though Core's own order (submission) would
            // put the on-time one first.
            ->assertSeeInOrder(['22005552', '22005551'])
            ->assertSee('nureen-charter nureen-charter-overdue', false)
            ->assertSee('Was due Wed 4 Nov 2026', false)
            ->assertSee('Due by <b>Mon 9 Nov 2026</b>', false);
    }

    protected function moved(Application $application, User $by, string $at): void
    {
        $row = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $by->id,
            'stage_key' => 'supervisor',
            'stage_label' => 'Lecturer/Supervisor',
            'decision' => 'endorsed',
        ]);

        DB::table('approval_history')->where('id', $row->id)->update(['created_at' => $at]);
    }
}
