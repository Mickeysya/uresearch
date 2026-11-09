<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The supervisor's full candidate list: the "My candidates" panel on the
 * dashboard (every candidate, scrollable) and the page behind its
 * "View all" link (SupervisorCandidatesController).
 */
class SupervisorCandidatesTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** Twelve students for $supervisor, more than the panel's old cap of 8. */
    protected function cohort(User $supervisor, int $count = 12, string $prefix = 'Student'): void
    {
        foreach (range(1, $count) as $i) {
            $this->student(
                matric: sprintf('2209%04d', $i + ($prefix === 'Student' ? 0 : 5000)),
                email: strtolower($prefix).$i.'@test.my',
                attributes: ['name' => sprintf('%s %02d', $prefix, $i), 'supervisor_id' => $supervisor->id, 'programme' => 'PhD Full-Time'],
            );
        }
    }

    public function test_the_dashboard_panel_lists_every_candidate_and_links_to_the_full_page(): void
    {
        $supervisor = $this->supervisor();
        $this->cohort($supervisor);

        $response = $this->actingAs($supervisor)->get(route('dashboard'))->assertOk();

        // All twelve, not the first eight -- and the list is the scrollable one.
        foreach (range(1, 12) as $i) {
            $response->assertSee(sprintf('Student %02d', $i));
        }
        $response->assertSee('class="approver-feed approver-scroll cand-list"', false)
            ->assertSee('View all 12')
            ->assertSee(route('supervisor.candidates'), false)
            ->assertDontSee('8 of 12');
    }

    public function test_the_attendance_ring_and_at_risk_count_cover_every_candidate(): void
    {
        $supervisor = $this->supervisor();
        $this->cohort($supervisor);

        // The ninth student, alphabetically -- past the old cap of 8 -- is flagged.
        $ninth = User::where('name', 'Student 09')->sole();
        AttendanceRecord::create([
            'student_id' => $ninth->id,
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
            'sessions_attended' => 50,
            'sessions_total' => 100,
            'percentage' => 50,
            'at_risk' => true,
        ]);

        $html = $this->actingAs($supervisor)->get(route('dashboard'))->assertOk()->getContent();

        // The ring counts all twelve (it used to count the cached 8).
        $this->assertMatchesRegularExpression('/CANDIDATES.*?12/is', strip_tags($html));
        $this->assertStringNotContainsString('nobody is flagged', $html);
    }

    public function test_the_page_lists_only_this_supervisors_students(): void
    {
        $supervisor = $this->supervisor();
        $other = $this->supervisor('other.sup@test.my');
        $this->cohort($supervisor);
        $this->cohort($other, 3, 'Theirs');

        $response = $this->actingAs($supervisor)->get(route('supervisor.candidates'))
            ->assertOk()
            ->assertSee('My candidates')
            ->assertSee('12 students under your supervision');

        foreach (range(1, 12) as $i) {
            $response->assertSee(sprintf('Student %02d', $i));
        }
        $response->assertDontSee('Theirs 01');
    }

    public function test_the_page_searches_by_name_matric_or_programme(): void
    {
        $supervisor = $this->supervisor();
        $this->cohort($supervisor);
        $this->student('22011111', 'farah@test.my', ['name' => 'Nur Farah Adilah', 'supervisor_id' => $supervisor->id, 'programme' => 'MSc Full-Time']);

        $this->actingAs($supervisor)->get(route('supervisor.candidates', ['q' => 'farah']))
            ->assertOk()
            ->assertSee('Nur Farah Adilah')
            ->assertDontSee('Student 01')
            ->assertSee('1 of 13 match');

        $this->get(route('supervisor.candidates', ['q' => '22011111']))->assertSee('Nur Farah Adilah');
        $this->get(route('supervisor.candidates', ['q' => 'msc']))->assertSee('Nur Farah Adilah')->assertDontSee('Student 02');

        $this->get(route('supervisor.candidates', ['q' => 'nobody like this']))
            ->assertSee('No candidate matches')
            ->assertSee('Show all candidates');
    }

    public function test_a_supervisor_with_no_students_is_told_so(): void
    {
        $this->actingAs($this->supervisor())->get(route('supervisor.candidates'))
            ->assertOk()
            ->assertSee('No students are assigned to you yet.');
    }

    public function test_only_supervisors_can_open_the_page(): void
    {
        $this->actingAs($this->student())->get(route('supervisor.candidates'))->assertForbidden();
        $this->actingAs($this->chair())->get(route('supervisor.candidates'))->assertForbidden();

        auth()->logout();
        $this->get(route('supervisor.candidates'))->assertRedirect();
    }
}
