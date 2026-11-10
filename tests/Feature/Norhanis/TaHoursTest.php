<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Contracts\SuppliesTeachingAssistantStatus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Http\Controllers\TaHourController;
use App\Modules\Norhanis\Models\TaCourseLecturer;
use App\Modules\Norhanis\Models\TaHourLog;
use App\Modules\Norhanis\Notifications\TaHoursAwaitingApproval;
use App\Modules\Norhanis\Notifications\TaHoursDecided;
use App\Modules\Norhanis\Support\Roles;
use App\Modules\Norhanis\Support\TaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Daily Log TA Hours: the GA/GRA student logs their own rows, the course
 * lecturer each row names approves them, and only approved hours count.
 */
class TaHoursTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00');
        Notification::fake();
        $this->appointment('GA');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_student_who_is_not_a_ga_or_gra_sees_the_caption_and_cannot_log(): void
    {
        $this->appointment(null);
        $student = $this->student();

        $this->actingAs($student)->get(route('ta-hours.index'))
            ->assertOk()
            ->assertSee('You are not a GA or GRA.')
            ->assertDontSee('Claim Month and Year');

        $this->get(route('ta-hours.sheet', [2026, 11]))->assertForbidden();
        $this->post(route('ta-hours.store', [2026, 11]), $this->row())->assertForbidden();
    }

    public function test_a_ga_picks_the_year_and_month_then_opens_that_sheet(): void
    {
        $this->actingAs($this->student())->get(route('ta-hours.index'))
            ->assertOk()
            ->assertSee('Please ensure to select the', false);

        $this->get(route('ta-hours.index', ['year' => 2026, 'month' => 11]))
            ->assertRedirect(route('ta-hours.sheet', [2026, 11]));

        $this->get(route('ta-hours.index', ['year' => 2026, 'month' => 12]))
            ->assertRedirect(route('ta-hours.index'))
            ->assertSessionHasErrors('month');
    }

    public function test_a_row_works_out_its_hours_from_time_in_and_time_out(): void
    {
        $student = $this->student();
        $lecturer = $this->lecturer();

        $this->actingAs($student)
            ->post(route('ta-hours.store', [2026, 11]), $this->row(['time_in' => '13:45', 'time_out' => '16:15']))
            ->assertRedirect(route('ta-hours.sheet', [2026, 11]));

        $log = TaHourLog::sole();
        $this->assertEquals(2.5, (float) $log->hours);
        $this->assertSame(TaHourLog::DRAFT, $log->state);
        $this->assertSame($lecturer->id, $log->course_lecturer_id);
        $this->assertSame('tutorial', $log->activity_type);

        $this->get(route('ta-hours.sheet', [2026, 11]))
            ->assertOk()
            ->assertSee('Dr. Hafiz Rahman (UTP1001)')
            ->assertSee('Not submitted');
    }

    public function test_the_lecturer_can_be_found_by_staff_code_alone(): void
    {
        $lecturer = $this->lecturer();

        $this->actingAs($this->student())
            ->post(route('ta-hours.store', [2026, 11]), $this->row(['course_lecturer' => 'utp1001']))
            ->assertRedirect();

        $this->assertSame($lecturer->id, TaHourLog::sole()->course_lecturer_id);
    }

    public function test_bad_rows_are_refused(): void
    {
        $this->lecturer();
        $this->actingAs($this->student());

        $cases = [
            'time_in' => ['time_in' => '1:45pm'],
            'time_out' => ['time_in' => '14:00', 'time_out' => '13:00'],
            'activity_date' => ['activity_date' => '2026-10-31'],          // not in November
            'activity_type' => ['activity_type' => 'Marking'],             // not on the list
            'course_lecturer' => ['course_lecturer' => 'Nobody (X999)'],
        ];

        foreach ($cases as $field => $override) {
            $this->post(route('ta-hours.store', [2026, 11]), $this->row($override))->assertSessionHasErrors($field);
        }

        // A future date in the current month.
        $this->post(route('ta-hours.store', [2026, 11]), $this->row(['activity_date' => '2026-11-20']))
            ->assertSessionHasErrors('activity_date');

        $this->assertSame(0, TaHourLog::count());
    }

    public function test_a_lecturer_who_is_not_a_lecturer_cannot_be_named(): void
    {
        $supervisor = $this->supervisor();
        TaCourseLecturer::create(['user_id' => $supervisor->id, 'staff_code' => 'SUP1']);

        $this->actingAs($this->student())
            ->post(route('ta-hours.store', [2026, 11]), $this->row(['course_lecturer' => 'SUP1']))
            ->assertSessionHasErrors('course_lecturer');
    }

    public function test_overlapping_rows_and_the_monthly_cap_are_refused(): void
    {
        $this->lecturer();
        $this->actingAs($this->student());

        $this->post(route('ta-hours.store', [2026, 11]), $this->row(['time_in' => '08:00', 'time_out' => '18:00']))->assertSessionHasNoErrors();
        $this->post(route('ta-hours.store', [2026, 11]), $this->row(['time_in' => '17:00', 'time_out' => '19:00']))->assertSessionHasErrors('time_in');

        $this->post(route('ta-hours.store', [2026, 11]), $this->row(['activity_date' => '2026-11-11', 'time_in' => '08:00', 'time_out' => '18:00']))->assertSessionHasNoErrors();
        // 20 hrs logged; 2 more would pass the 21-hour cap.
        $this->post(route('ta-hours.store', [2026, 11]), $this->row(['activity_date' => '2026-11-12', 'time_in' => '08:00', 'time_out' => '10:00']))->assertSessionHasErrors('time_out');

        $this->assertSame(2, TaHourLog::count());
    }

    public function test_submitting_sends_drafts_to_the_lecturer_they_name(): void
    {
        $student = $this->student();
        $hafiz = $this->lecturer();
        $siti = $this->lecturer('Dr. Siti Aminah', 'siti@test.my', 'UTP1002');

        $this->actingAs($student);
        $this->post(route('ta-hours.store', [2026, 11]), $this->row());
        $this->post(route('ta-hours.store', [2026, 11]), $this->row(['activity_date' => '2026-11-11', 'course_lecturer' => 'UTP1002']));

        $this->post(route('ta-hours.submit', [2026, 11]))->assertRedirect(route('ta-hours.sheet', [2026, 11]));

        $this->assertSame(2, TaHourLog::where('state', TaHourLog::PENDING)->count());
        Notification::assertSentTo($hafiz, TaHoursAwaitingApproval::class);
        Notification::assertSentTo($siti, TaHoursAwaitingApproval::class);

        // Pending rows can no longer be removed.
        $this->delete(route('ta-hours.destroy', TaHourLog::first()))->assertForbidden();
    }

    public function test_a_lecturer_sees_and_decides_only_rows_naming_them(): void
    {
        $student = $this->student();
        $hafiz = $this->lecturer();
        $siti = $this->lecturer('Dr. Siti Aminah', 'siti@test.my', 'UTP1002');

        $mine = $this->pending($student, $hafiz, ['activity_type' => 'tutorial']);
        $hers = $this->pending($student, $siti, ['activity_type' => 'invigilation', 'activity_date' => '2026-11-11']);

        $this->actingAs($hafiz)->get(route('ta-hours.approvals'))
            ->assertOk()
            ->assertSee('Tutorial')
            ->assertDontSee('Invigilation of Final Exam');

        $this->post(route('ta-hours.decide'), ['ids' => [$mine->id, $hers->id], 'decision' => 'approve'])->assertRedirect();

        $this->assertSame(TaHourLog::APPROVED, $mine->fresh()->state);
        $this->assertSame(TaHourLog::PENDING, $hers->fresh()->state);
        Notification::assertSentTo($student, TaHoursDecided::class);
    }

    public function test_a_rejection_needs_a_reason_and_the_row_can_then_be_removed(): void
    {
        $student = $this->student();
        $hafiz = $this->lecturer();
        $log = $this->pending($student, $hafiz);

        $this->actingAs($hafiz)->post(route('ta-hours.decide'), ['ids' => [$log->id], 'decision' => 'reject'])
            ->assertSessionHasErrors('reason');

        $this->post(route('ta-hours.decide'), ['ids' => [$log->id], 'decision' => 'reject', 'reason' => 'Wrong date'])->assertRedirect();
        $this->assertSame(TaHourLog::REJECTED, $log->fresh()->state);
        $this->assertSame('Wrong date', $log->fresh()->decision_reason);

        $this->actingAs($student)->delete(route('ta-hours.destroy', $log))->assertRedirect();
        $this->assertNull($log->fresh());
    }

    public function test_only_approved_hours_count(): void
    {
        $student = $this->student();
        $hafiz = $this->lecturer();

        $this->pending($student, $hafiz, ['hours' => 3, 'state' => TaHourLog::APPROVED]);
        $this->pending($student, $hafiz, ['hours' => 5, 'activity_date' => '2026-11-11']);
        $this->pending($student, $hafiz, ['hours' => 7, 'activity_date' => '2026-11-12', 'state' => TaHourLog::DRAFT]);

        $this->assertSame(3.0, (new TaHourController)->thisMonthFor($student)['hours']);
        $this->assertSame(3.0, TaStatus::hoursBetween($student, Carbon::create(2026, 9, 1), Carbon::today()));
    }

    public function test_a_student_sees_only_their_own_rows(): void
    {
        $mine = $this->student();
        $other = $this->student('22009999', 'other@test.my');
        $hafiz = $this->lecturer();

        $this->pending($mine, $hafiz, ['time_in' => '09:00', 'time_out' => '11:00']);
        $this->pending($other, $hafiz, ['time_in' => '07:15', 'time_out' => '08:45']);

        // Times, not activities: the sheet's own drop-down lists every activity.
        $this->actingAs($mine)->get(route('ta-hours.sheet', [2026, 11]))
            ->assertSee('09:00')
            ->assertDontSee('07:15');

        $theirs = TaHourLog::where('student_id', $other->id)->sole();
        $theirs->update(['state' => TaHourLog::DRAFT]);
        $this->delete(route('ta-hours.destroy', $theirs))->assertForbidden();
    }

    public function test_staff_can_view_but_no_longer_enter_hours(): void
    {
        $supervisor = $this->supervisor();
        $mine = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $notMine = $this->student('22009999', 'other@test.my', ['name' => 'Someone Else']);
        $hafiz = $this->lecturer();

        $this->pending($mine, $hafiz);
        $this->pending($notMine, $hafiz);

        $this->actingAs($supervisor)->get(route('ta-hours.index'))
            ->assertOk()
            ->assertSee('Ahmad Danial')
            ->assertDontSee('Someone Else')
            ->assertDontSee('Record Hours');

        $this->post(route('ta-hours.store', [2026, 11]), $this->row())->assertForbidden();
        $this->get(route('ta-hours.approvals'))->assertForbidden();

        $this->actingAs($this->chair())->get(route('ta-hours.index'))->assertForbidden();
    }

    public function test_the_links_reach_each_role(): void
    {
        $this->actingAs($this->student())->get(route('dashboard'))->assertSee(route('ta-hours.index'), false);

        $lecturer = $this->lecturer();
        $this->actingAs($lecturer)->get(route('ta-hours.index'))->assertRedirect(route('ta-hours.approvals'));
        $this->get(route('dashboard'))->assertOk()->assertSee(route('ta-hours.approvals'), false);
    }

    /* ---------------- helpers ---------------- */

    /** Stand in for Haziq's module: every student holds $type ('GA', 'GRA' or null for none). */
    protected function appointment(?string $type): void
    {
        $this->app->instance(SuppliesTeachingAssistantStatus::class, new class($type) implements SuppliesTeachingAssistantStatus
        {
            public function __construct(private ?string $type) {}

            public function currentAppointmentFor(User $student): ?array
            {
                return $this->type ? ['type' => $this->type, 'since' => now()->subYear()] : null;
            }
        });
    }

    protected function lecturer(string $name = 'Dr. Hafiz Rahman', string $email = 'lecturer@test.my', string $code = 'UTP1001'): User
    {
        $user = $this->user(Roles::LECTURER, ['name' => $name, 'email' => $email]);
        TaCourseLecturer::create(['user_id' => $user->id, 'staff_code' => $code]);

        return $user;
    }

    protected function row(array $overrides = []): array
    {
        return $overrides + [
            'activity_date' => '2026-11-10',
            'time_in' => '09:00',
            'time_out' => '11:00',
            'activity_type' => 'tutorial',
            'course_lecturer' => 'Dr. Hafiz Rahman (UTP1001)',
        ];
    }

    protected function pending(User $student, User $lecturer, array $attributes = []): TaHourLog
    {
        return TaHourLog::create($attributes + [
            'student_id' => $student->id,
            'activity_type' => 'tutorial',
            'activity_date' => '2026-11-10',
            'time_in' => '09:00',
            'time_out' => '11:00',
            'hours' => 2,
            'course_lecturer_id' => $lecturer->id,
            'state' => TaHourLog::PENDING,
            'submitted_at' => now(),
        ]);
    }
}
