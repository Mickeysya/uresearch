<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Support\Role;
use App\Modules\Norhanis\Models\TaHourLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The TA Hours page, handed over from Haziq's module: same URL, same route
 * names, same ta_hour_logs table -- now served from app/Modules/Norhanis.
 */
class TaHoursTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    public function test_a_student_sees_only_their_own_hours(): void
    {
        $mine = $this->student();
        $other = $this->student('22009999', 'other@test.my');

        TaHourLog::create(['student_id' => $mine->id, 'activity_type' => 'Lab session', 'activity_date' => now()->toDateString(), 'hours' => 3]);
        TaHourLog::create(['student_id' => $other->id, 'activity_type' => 'Marking Someone Else', 'activity_date' => now()->toDateString(), 'hours' => 5]);

        $this->actingAs($mine)->get(route('ta-hours.index'))
            ->assertOk()
            ->assertSee('Lab session')
            ->assertDontSee('Marking Someone Else');
    }

    public function test_a_supervisor_logs_hours_for_their_own_supervisee_only(): void
    {
        $supervisor = $this->supervisor();
        $mine = $this->student(attributes: ['supervisor_id' => $supervisor->id]);
        $notMine = $this->student('22009999', 'other@test.my');

        $entry = ['activity_type' => 'Tutorial', 'activity_date' => now()->toDateString(), 'hours' => 2];

        $this->actingAs($supervisor)
            ->post(route('ta-hours.store'), $entry + ['student_id' => $mine->id])
            ->assertRedirect();
        $this->assertEquals(2, TaHourLog::where('student_id', $mine->id)->sum('hours'));
        $this->assertSame($supervisor->id, TaHourLog::sole()->recorded_by);

        $this->post(route('ta-hours.store'), $entry + ['student_id' => $notMine->id])->assertForbidden();
        $this->assertSame(0, TaHourLog::where('student_id', $notMine->id)->count());
    }

    public function test_a_student_cannot_log_hours(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->post(route('ta-hours.store'), ['student_id' => $student->id, 'activity_type' => 'Lab', 'activity_date' => now()->toDateString(), 'hours' => 21])
            ->assertForbidden();
    }

    public function test_the_link_is_on_the_travel_section_for_the_roles_the_page_lets_in(): void
    {
        $this->actingAs($this->student())->get(route('dashboard'))->assertSee(route('ta-hours.index'), false);

        $chair = $this->user(Role::CHAIR, ['email' => 'chair@test.my']);
        $this->actingAs($chair)->get(route('ta-hours.index'))->assertForbidden();
    }
}
