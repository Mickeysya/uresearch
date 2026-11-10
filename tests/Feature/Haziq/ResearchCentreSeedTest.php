<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\User;
use App\Modules\Haziq\Support\Roles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GaApplicationWorkflow's research_centre_interview stage is department-
 * scoped (GaApplicationController compares $application->student->department
 * against $request->user()->department -- see its queue()/decide()/
 * scheduleInterview()). Before 2026-11-10 the seeder only ever created ONE
 * Research Centre account, with department literally 'Research Centre' --
 * a value no real student department can ever match, so every seeded GA
 * application would sit at Interview forever with nobody able to pick it
 * up. Confirmed explicitly: "astu siapa yang nak kasi interview date, send
 * interview email untuk ga skang ni, role tu kene ade mat."
 *
 * Fixed the same way Chair and Academic Executive already were: one
 * account per department (RESEARCH_CENTRES), with the roster account
 * (researchcentre@utp.edu.my) covering Computing.
 */
class ResearchCentreSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_department_has_a_research_centre_reviewer_who_can_actually_match_a_student(): void
    {
        $this->seed(DatabaseSeeder::class);

        $departments = User::where('role', 'student')->distinct()->pluck('department');
        $this->assertNotEmpty($departments, 'Seeded no students, so this test cannot prove anything.');

        $reviewerDepartments = User::where('role', Roles::RESEARCH_CENTRE)->pluck('department');

        foreach ($departments as $department) {
            $this->assertTrue(
                $reviewerDepartments->contains($department),
                "No Research Centre reviewer is seeded for '{$department}' -- every GA application from that department would sit at Interview with nobody able to pick it up."
            );
        }
    }

    public function test_the_roster_account_covers_computing_not_a_placeholder_department(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Computing', User::where('email', 'researchcentre@utp.edu.my')->value('department'));
    }
}
