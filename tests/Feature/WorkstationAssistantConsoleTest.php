<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Chloe\Models\StudentGender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** PART 8's three non-evaluate console commands -- assistant:evaluate is excluded here on purpose: it spends real provider quota and is only ever run by a human, per its own class doc. */
class WorkstationAssistantConsoleTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakeAssistant();
    }

    public function test_assistant_check_reports_ok_under_the_fake_provider(): void
    {
        $this->artisan('assistant:check')
            ->expectsOutputToContain('Provider: fake')
            ->assertSuccessful();
    }

    /** Regression test for a bug found live: the response time was computed with Carbon's diffInMilliseconds() and came back negative. */
    public function test_assistant_check_never_reports_a_negative_response_time(): void
    {
        $this->artisan('assistant:check')
            ->doesntExpectOutputToContain('Response time: -')
            ->assertSuccessful();
    }

    public function test_assistant_check_reports_a_missing_model_without_crashing(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini.model' => null]);

        $this->artisan('assistant:check')
            ->expectsOutputToContain('(not set)')
            ->assertFailed();
    }

    public function test_assistant_ask_runs_the_pipeline_and_prints_the_result(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);

        $this->artisan('assistant:ask', ['email' => $student->email, 'question' => 'how many seats are available'])
            ->expectsOutputToContain('Outcome: answered')
            ->assertSuccessful();
    }

    public function test_assistant_ask_fails_cleanly_for_an_unknown_email(): void
    {
        $this->artisan('assistant:ask', ['email' => 'nobody@test.my', 'question' => 'hello'])
            ->assertFailed();
    }

    public function test_assistant_log_prints_the_latest_rows(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        FakeModel::queueAnswer(['kind' => 'out_of_scope']);
        app(Pipeline::class)->ask($student, 'xyz', []);

        $this->artisan('assistant:log', ['--limit' => 5])
            ->assertSuccessful();

        $this->assertGreaterThan(0, AssistantLog::count());
    }

    public function test_assistant_log_with_no_rows_succeeds_quietly(): void
    {
        $this->artisan('assistant:log')
            ->expectsOutputToContain('No assistant_logs rows yet.')
            ->assertSuccessful();
    }
}
