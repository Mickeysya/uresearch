<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\GeminiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PART 1 #3: the key must never appear in a log, exception message, stack
 * trace, console output or response -- this is GeminiModel's own test for
 * that guarantee, independent of WorkstationAssistantTest (which runs
 * entirely under FakeModel and never touches GeminiModel at all).
 *
 * The secret value used here is a fake, test-only string, never a real
 * key -- GEMINI_API_KEY itself is never read by this file.
 */
class GeminiKeySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected const FAKE_SECRET = 'TEST-ONLY-SECRET-KEY-fFgH92kLmP';

    protected function setUp(): void
    {
        parent::setUp();

        config(['assistant.gemini.api_key' => self::FAKE_SECRET, 'assistant.gemini.model' => 'gemini-3.8-flash']);
    }

    public function test_the_key_is_sent_only_in_the_header_never_in_the_url(): void
    {
        Http::fake(['*' => Http::response(['status' => 'completed', 'steps' => [
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"out_of_scope"}']]],
        ]], 200)]);

        (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString(self::FAKE_SECRET, $request->url());
            $this->assertSame(self::FAKE_SECRET, $request->header('x-goog-api-key')[0] ?? null);

            return true;
        });
    }

    public function test_a_server_error_never_leaks_the_key_in_the_result(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $result = (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertStringNotContainsString(self::FAKE_SECRET, json_encode($result));
    }

    public function test_a_connection_exception_never_leaks_the_key_anywhere(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused for a secret-bearing request');
        });

        $thrown = null;

        try {
            $result = (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // GeminiModel must catch this itself -- nothing should escape to the caller at all.
        $this->assertNull($thrown, 'GeminiModel must not let a connection exception escape.');
        $this->assertSame('unavailable', $result['outcome']);
        $this->assertStringNotContainsString(self::FAKE_SECRET, json_encode($result));
    }

    public function test_a_429_response_never_leaks_the_key_in_the_result(): void
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        $result = (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertStringNotContainsString(self::FAKE_SECRET, json_encode($result));
    }

    public function test_composeaggregate_also_never_leaks_the_key_on_failure(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $result = (new GeminiModel)->composeAggregate('a question', 'overall_stats', ['total' => 1], 'gemini-3.8-flash', 15);

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertStringNotContainsString(self::FAKE_SECRET, json_encode($result));
    }

    /** Round 3 PART A: the error_code GeminiModel reports is the real HTTP status (as a string) -- Pipeline's retry/fallback logic depends on telling a 429 apart from a 503 from this field. */
    public function test_the_error_code_is_the_real_http_status_for_a_503(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);
        $result = (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);
        $this->assertSame('503', $result['error_code']);
    }

    public function test_the_error_code_is_the_real_http_status_for_a_429(): void
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);
        $result = (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);
        $this->assertSame('429', $result['error_code']);
    }

    public function test_the_error_code_is_timeout_for_a_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });
        $result = (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);
        $this->assertSame('timeout', $result['error_code']);
    }

    public function test_an_empty_key_or_model_never_makes_an_http_call(): void
    {
        config(['assistant.gemini.api_key' => null]);
        Http::fake();

        $result = (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15);

        $this->assertSame('unavailable', $result['outcome']);
        Http::assertNothingSent();
    }

    /**
     * Round 5 PART A, not key safety -- reuses this file's own Http::fake()
     * + assertSent() pattern (the only place GeminiModel is driven
     * directly against a faked HTTP call) to check the actual request
     * body carries the CGS voice instruction when $isCgsRole is true,
     * and never when it is false.
     */
    public function test_the_cgs_voice_instruction_is_sent_only_when_isCgsRole_is_true(): void
    {
        Http::fake(['*' => Http::response(['status' => 'completed', 'steps' => [
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"out_of_scope"}']]],
        ]], 200)]);

        (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15, [], false, true);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('address THEM, never the student', $request['system_instruction']);

            return true;
        });

        Http::fake(['*' => Http::response(['status' => 'completed', 'steps' => [
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"kind":"out_of_scope"}']]],
        ]], 200)]);

        (new GeminiModel)->answer('a question', [], '', [], 'gemini-3.8-flash', 15, [], false, false);

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString('address THEM, never the student', $request['system_instruction']);

            return true;
        });
    }
}
