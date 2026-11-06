<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Http\Controllers\AssistantController;
use App\Modules\Haziq\Models\AssistantLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    protected function student(): User
    {
        return User::create(['name' => 'Student', 'email' => 'student@test.com', 'password' => 'password', 'role' => Role::STUDENT]);
    }

    protected function cgs(): User
    {
        return User::create(['name' => 'CGS', 'email' => 'cgs@test.com', 'password' => 'password', 'role' => Role::NON_EXEC_CGS]);
    }

    public function test_a_non_student_gets_403(): void
    {
        $this->actingAs($this->cgs())
            ->postJson(route('haziq-assistant.ask'), ['question' => 'test'])
            ->assertForbidden();
    }

    public function test_a_question_is_required(): void
    {
        $this->actingAs($this->student())
            ->postJson(route('haziq-assistant.ask'), [])
            ->assertInvalid(['question']);
    }

    public function test_a_question_over_500_characters_is_rejected(): void
    {
        $this->actingAs($this->student())
            ->postJson(route('haziq-assistant.ask'), ['question' => str_repeat('a', 501)])
            ->assertInvalid(['question']);
    }

    public function test_a_successful_answer_is_returned_and_logged_with_outcome_ok(): void
    {
        config(['haziq_assistant.api_key' => 'test-key']);
        Http::fake([
            self::ENDPOINT => Http::response([
                'status' => 'completed',
                'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Here is your answer.']]]],
            ], 200),
        ]);

        $student = $this->student();

        $this->actingAs($student)
            ->postJson(route('haziq-assistant.ask'), ['question' => 'when is my deadline'])
            ->assertOk()
            ->assertJson(['answer' => 'Here is your answer.']);

        $log = AssistantLog::where('student_id', $student->id)->first();
        $this->assertSame('ok', $log->outcome);
        $this->assertSame('when is my deadline', $log->question);
    }

    public function test_a_model_failure_returns_a_fallback_message_instead_of_an_error(): void
    {
        config(['haziq_assistant.api_key' => null]);

        $student = $this->student();

        $response = $this->actingAs($student)
            ->postJson(route('haziq-assistant.ask'), ['question' => 'anything'])
            ->assertOk();

        $this->assertStringContainsString("couldn't reach the assistant", $response->json('answer'));

        $log = AssistantLog::where('student_id', $student->id)->first();
        $this->assertSame('unavailable', $log->outcome);
        $this->assertSame('no_credentials', $log->error_code);
    }

    public function test_the_daily_cap_blocks_further_questions_without_logging_them(): void
    {
        $student = $this->student();

        for ($i = 0; $i < AssistantController::DAILY_CAP; $i++) {
            AssistantLog::create([
                'student_id' => $student->id, 'question' => "question {$i}",
                'answer' => 'answer', 'outcome' => 'ok',
            ]);
        }

        $before = AssistantLog::where('student_id', $student->id)->count();

        $this->actingAs($student)
            ->postJson(route('haziq-assistant.ask'), ['question' => 'one more'])
            ->assertStatus(429);

        $this->assertSame($before, AssistantLog::where('student_id', $student->id)->count());
    }
}
