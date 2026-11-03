<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** The examiner availability dashboard: scoping, the returning list, stages and expertise gaps. */
class ExaminerDashboardTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function examiner(string $name, string $department, array $attributes = [], array $keywords = []): Examiner
    {
        $examiner = Examiner::create($attributes + [
            'name' => $name, 'email' => str($name)->slug().'@example.com', 'department' => $department,
            'type' => Examiner::TYPE_INTERNAL, 'is_active' => true,
        ]);

        foreach ($keywords as $keyword) {
            $examiner->expertiseKeywords()->create(['keyword' => $keyword]);
        }

        return $examiner;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->examiner('Dr Back Soon', 'Computing', ['last_examination_date' => now()->subDays(80)], ['machine learning']);
        $this->examiner('Dr Back Later', 'Computing', ['last_examination_date' => now()->subDays(30)]);
        $this->examiner('Dr Free', 'Computing', [], ['machine learning', 'anomaly detection']);
        $this->examiner('Dr Petroleum', 'Petroleum Engineering', ['last_examination_date' => now()->subDays(85)]);

        $student = $this->student(attributes: ['department' => 'Computing']);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_PENDING, 'current_stage' => 'cgs_compile']);
        ExaminerNomination::create(['application_id' => $application->id, 'thesis_title' => 'Geopolymer Concrete with Machine Learning',
            'internal_main_id' => Examiner::where('name', 'Dr Free')->value('id')]);
    }

    public function test_the_academic_executive_sees_their_own_department(): void
    {
        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);

        $response = $this->actingAs($ae)->get(route('examiner-dashboard'))->assertOk();

        $this->assertSame(3, $response->viewData('total'));
        $this->assertSame(['available' => 1, 'on_gap' => 2, 'assigned' => 0, 'unavailable' => 0], $response->viewData('states'));

        // Back within 30 days: Dr Back Soon (10 days); not Dr Back Later (60), not another department's.
        $response->assertSee('Dr Back Soon')->assertDontSee('Dr Back Later')->assertDontSee('Dr Petroleum');
    }

    public function test_cgs_the_senior_director_and_the_dean_see_everything(): void
    {
        foreach ([$this->seniorExec(), $this->seniorDirector(), $this->dean()] as $user) {
            $response = $this->actingAs($user)->get(route('examiner-dashboard'))->assertOk();
            $this->assertSame(4, $response->viewData('total'));
            $response->assertSee('Dr Petroleum');
        }
    }

    public function test_nobody_else_sees_it(): void
    {
        foreach ([$this->cgs(), $this->student('22000099', 'x@test.my'), $this->supervisor()] as $user) {
            $this->actingAs($user)->get(route('examiner-dashboard'))->assertForbidden();
        }
    }

    public function test_nominations_are_counted_by_stage(): void
    {
        $stages = collect($this->actingAs($this->seniorExec())->get(route('examiner-dashboard'))->viewData('stages'))
            ->pluck('count', 'label');

        $this->assertSame(1, $stages['CGS Compilation']);
        $this->assertSame(0, $stages['Academic Executive']);
    }

    public function test_a_thesis_topic_nobody_covers_is_flagged_as_a_gap(): void
    {
        $response = $this->actingAs($this->seniorExec())->get(route('examiner-dashboard'))->assertOk();
        $gaps = collect($response->viewData('gaps'))->keyBy('topic');

        $this->assertTrue($gaps['geopolymer']['gap'], 'No examiner lists geopolymer.');
        $this->assertSame(0, $gaps['geopolymer']['examiners']);
        $this->assertSame(2, $gaps['machine learning']['examiners'], 'Matched as the pool\'s phrase, not as two words.');
        $this->assertFalse($gaps['machine learning']['gap']);

        $response->assertSee('id="examiner-dashboard-data"', false)->assertSee('js/hani/examiner-dashboard.js', false);
    }
}
