<?php

namespace Tests\Feature\Haziq;

use App\Modules\Haziq\Assistant\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The one HTTP seam, tested the same way GeminiModel's own test suite
 * would -- Http::fake() intercepts the real network call regardless of
 * whatever key is actually sitting in .env, so this never costs real
 * quota and never depends on Google's API being up.
 *
 * The key itself is overridden via config(['haziq_assistant.api_key' =>
 * ...]), not putenv()/$_ENV -- Laravel's env() does not reliably pick up
 * either of those once the framework has already booted, which is the
 * whole reason every env() call in this app lives inside a config file
 * instead of being called directly (see config/haziq_assistant.php).
 * Each test gets a fresh application instance, so nothing needs to be
 * restored afterward.
 */
class GeminiClientTest extends TestCase
{
    protected const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    public function test_it_reports_no_credentials_when_the_key_is_missing(): void
    {
        config(['haziq_assistant.api_key' => null]);

        $result = (new GeminiClient)->ask('question', 'context');

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame('no_credentials', $result['error_code']);
        $this->assertNull($result['answer']);
    }

    public function test_it_extracts_the_answer_from_a_successful_response(): void
    {
        config(['haziq_assistant.api_key' => 'test-key']);
        Http::fake([
            self::ENDPOINT => Http::response([
                'status' => 'completed',
                'steps' => [
                    ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Your deadline is 1 Dec 2026.']]],
                ],
            ], 200),
        ]);

        $result = (new GeminiClient)->ask('when is my deadline', 'context text');

        $this->assertSame('ok', $result['outcome']);
        $this->assertSame('Your deadline is 1 Dec 2026.', $result['answer']);
        $this->assertNull($result['error_code']);
    }

    public function test_the_question_and_context_are_actually_sent_in_the_request_body(): void
    {
        config(['haziq_assistant.api_key' => 'test-key']);
        Http::fake([self::ENDPOINT => Http::response(['status' => 'completed', 'steps' => []], 200)]);

        (new GeminiClient)->ask('my exact question', 'my exact context');

        Http::assertSent(fn ($request) => $request['input'] === 'my exact question'
            && str_contains($request['system_instruction'], 'my exact context')
            && $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function test_it_reports_unavailable_on_a_non_200_response(): void
    {
        config(['haziq_assistant.api_key' => 'test-key']);
        Http::fake([self::ENDPOINT => Http::response([], 503)]);

        $result = (new GeminiClient)->ask('question', 'context');

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame('503', $result['error_code']);
        $this->assertNull($result['answer']);
    }

    public function test_it_reports_unavailable_on_a_malformed_response_body(): void
    {
        config(['haziq_assistant.api_key' => 'test-key']);
        Http::fake([self::ENDPOINT => Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => []]]], 200)]);

        $result = (new GeminiClient)->ask('question', 'context');

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame('invalid_response', $result['error_code']);
    }

    public function test_a_connection_failure_is_reported_without_leaking_anything_about_the_request(): void
    {
        config(['haziq_assistant.api_key' => 'test-key']);
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: could not connect');
        });

        $result = (new GeminiClient)->ask('question', 'context');

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame('no_connection', $result['error_code']);
        $this->assertNull($result['answer']);
    }
}
