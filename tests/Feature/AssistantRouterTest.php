<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\Catalogue;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\GeminiModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Assistant\RouterFewShots;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The semantic router (Parts 2-6): validation of what the model chose,
 * execution by kind, "Interpreted as" and the "Did you mean" chips with
 * their no-model click path, follow-ups, the exact router payload
 * (privacy), every Part 5 fallback path, the metadata-only log, the two
 * original failing questions and the contrast groups as regressions, and
 * the role limits. FakeModel throughout -- never the real provider.
 */
class AssistantRouterTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakeAssistant();
        // Today, for every relative period below: Friday 30 October 2026 in Kuala Lumpur.
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00', 'Asia/Kuala_Lumpur'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function pipeline(): Pipeline
    {
        return app(Pipeline::class);
    }

    protected function room(string $code = 'N2-02-01', string $block = 'N2', string $gender = 'male'): WorkstationLocation
    {
        return WorkstationLocation::firstOrCreate(['room_code' => $code], ['block' => $block, 'gender' => $gender, 'name' => $code]);
    }

    protected function seat(string $code = '1', string $status = Workstation::STATUS_AVAILABLE, ?WorkstationLocation $room = null): Workstation
    {
        return Workstation::create(['workstation_location_id' => ($room ?? $this->room())->id, 'seat_code' => $code, 'position' => 1, 'status' => $status]);
    }

    protected function lastLog(): AssistantLog
    {
        return AssistantLog::latest('id')->first();
    }

    // -------------------------------------------------------------
    // Validation of the router's output
    // -------------------------------------------------------------

    public function test_an_unknown_id_is_invalid_output_and_the_fallback_answers(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'made_up_entry', 'confidence' => 0.99]);

        $result = $this->pipeline()->ask($cgs, 'how many keys are pending collection', []);

        $this->assertSame('keys_pending_collection', $this->lastLog()->lookup);
        $this->assertSame(AssistantLog::ANSWERED_BY_RULE_FALLBACK, $this->lastLog()->answered_by);
        $this->assertSame('answered', $result['outcome']);
    }

    public function test_a_cgs_id_for_a_student_is_invalid_output(): void
    {
        $student = $this->eligibleStudent();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2026-11'], 'confidence' => 0.95]);

        $result = $this->pipeline()->ask($student, 'how many seats will be released next month', []);

        $this->assertNotSame('release_forecast', $this->lastLog()->lookup);
        $this->assertSame(AssistantLog::ANSWERED_BY_RULE_FALLBACK, $this->lastLog()->answered_by);
        $this->assertSame(Pipeline::STAFF_ONLY_MESSAGE, $result['answer']);
    }

    public function test_a_period_that_does_not_validate_asks_back(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2026-13']]);

        $result = $this->pipeline()->ask($cgs, 'how many seats will be released in smarch', []);

        $this->assertSame('asked_back', $result['outcome']);
        $this->assertSame('Which month, quarter or year would you like the forecast for?', $result['answer']);
        $this->assertSame('How many seats may be released in the given period?', $result['interpreted_as']);
    }

    public function test_a_missing_period_asks_back(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast']);

        $result = $this->pipeline()->ask($cgs, 'release forecast', []);

        $this->assertSame('asked_back', $result['outcome']);
    }

    public function test_a_period_far_in_the_future_is_rejected_never_clamped(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2035-01']]);

        $this->assertSame('asked_back', $this->pipeline()->ask($cgs, 'releases in january 2035', [])['outcome']);
    }

    public function test_unknown_parameter_names_are_dropped(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'overall_stats', 'params' => ['block' => 'N2', 'drop_table' => 'x']]);

        $result = $this->pipeline()->ask($cgs, 'overall totals', []);

        $this->assertSame('What are the overall seat totals?', $result['interpreted_as'], 'overall_stats declares no parameters, so none are used or shown.');
    }

    public function test_gemini_router_json_parsing_rejects_and_filters(): void
    {
        $ids = ['overall_stats', 'cgs_release'];

        $this->assertSame('invalid_response', GeminiModel::parseRouterJson('not json at all', $ids)['error_code']);
        $this->assertSame('invalid_response', GeminiModel::parseRouterJson('{"id":"invented"}', $ids)['error_code']);

        $ok = GeminiModel::parseRouterJson('{"id":"overall_stats","confidence":1.7,"params":{"block":"N2","evil":"x","period":{"a":1}},"alternatives":["cgs_release","overall_stats","nope","cgs_release"]}', $ids);
        $this->assertSame('ok', $ok['outcome']);
        $this->assertSame(1.0, $ok['confidence']);
        $this->assertSame(['block' => 'N2'], $ok['params']);
        $this->assertSame(['cgs_release'], $ok['alternatives']);
    }

    // -------------------------------------------------------------
    // Execution by kind, and "Interpreted as"
    // -------------------------------------------------------------

    public function test_a_lookup_runs_the_existing_template_and_shows_interpreted_as(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2026-11'], 'confidence' => 0.97]);

        $result = $this->pipeline()->ask($cgs, 'how many seat will be release in November 2026?', []);

        $this->assertSame('answered', $result['outcome']);
        $this->assertStringContainsString('may be released in November 2026', $result['answer']);
        $this->assertSame('How many seats may be released in November 2026?', $result['interpreted_as']);
        $this->assertArrayNotHasKey('chips', $result);
        $this->assertSame('lookup', $this->lastLog()->catalogue_kind);
    }

    public function test_a_quarter_period_is_shown_as_a_quarter(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2027-01..2027-03']]);

        $result = $this->pipeline()->ask($cgs, 'which seat will be free in Q1 2027', []);

        $this->assertSame('How many seats may be released in Q1 2027?', $result['interpreted_as']);
    }

    public function test_guidance_uses_the_topic_text_when_compose_is_unavailable(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'cgs_release']);
        foreach (range(1, 4) as $i) {
            FakeModel::queueFailure('503');
        }

        $result = $this->pipeline()->ask($cgs, 'how do i release a seat', []);

        $this->assertSame('answered', $result['outcome']);
        $this->assertSame('How do I release a seat?', $result['interpreted_as']);
        $this->assertSame('guidance:cgs_release', $this->lastLog()->lookup);
        $this->assertSame('guidance', $this->lastLog()->catalogue_kind);
    }

    /** Found live: a compose text saying "the knowledge text does not cover this" was accepted -- the topic text is better. */
    public function test_a_compose_text_that_says_not_covered_falls_back_to_the_topic_text(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'cgs_keys']);
        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'The provided knowledge text does not cover what to do if a key is lost; please contact CGS.']);

        $result = $this->pipeline()->ask($cgs, 'what if student lost key lah', []);

        $this->assertStringContainsString('Release Hold with the reason "Key lost"', $result['answer']);
    }

    public function test_an_action_is_never_executed(): void
    {
        $cgs = $this->cgs();
        $seat = $this->seat('252', Workstation::STATUS_OCCUPIED);
        FakeModel::queueRoute(['id' => 'action_release', 'params' => ['seat_number' => $seat->id]]);

        $result = $this->pipeline()->ask($cgs, "pls release seat {$seat->id}", []);

        $this->assertSame('refused', $result['outcome']);
        $this->assertSame(Workstation::STATUS_OCCUPIED, $seat->fresh()->status);
        $this->assertSame('action', $this->lastLog()->catalogue_kind);
    }

    public function test_out_of_scope_gives_the_refusal_without_interpreted_as(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'out_of_scope', 'confidence' => 0.99]);

        $result = $this->pipeline()->ask($cgs, 'who won the football', []);

        $this->assertSame(Pipeline::OUT_OF_SCOPE_MESSAGE, $result['answer']);
        $this->assertNull($result['interpreted_as']);
        $this->assertSame('out_of_scope', $this->lastLog()->catalogue_kind);
    }

    // -------------------------------------------------------------
    // "Did you mean" chips and the no-model click path
    // -------------------------------------------------------------

    public function test_low_confidence_shows_did_you_mean_chips_best_first(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'seat_release_outlook', 'confidence' => 0.4, 'alternatives' => ['release_forecast', 'releases_this_week']]);

        $result = $this->pipeline()->ask($cgs, 'seat free thing', []);

        $this->assertSame(['seat_release_outlook', 'release_forecast', 'releases_this_week'], array_column($result['chips'], 'id'));
        $this->assertSame('Which seats are likely to become free in the next 3 months?', $result['chips'][0]['text']);
    }

    public function test_an_alternative_alone_shows_chips_even_at_high_confidence(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'keys_pending_return', 'confidence' => 0.9, 'alternatives' => ['return_outlook']]);

        $result = $this->pipeline()->ask($cgs, 'keys late', []);

        $this->assertSame(['keys_pending_return', 'return_outlook'], array_column($result['chips'], 'id'));
    }

    public function test_the_threshold_is_configurable(): void
    {
        $cgs = $this->cgs();
        config(['assistant.router_min_confidence' => 0.95]);
        FakeModel::queueRoute(['id' => 'overall_stats', 'confidence' => 0.9]);

        $this->assertNotEmpty($this->pipeline()->ask($cgs, 'totals', [])['chips']);
    }

    public function test_an_alternative_the_role_cannot_use_is_never_offered(): void
    {
        $student = $this->eligibleStudent();
        FakeModel::queueRoute(['id' => 'available_seats', 'confidence' => 0.4, 'alternatives' => ['overall_stats']]);

        $result = $this->pipeline()->ask($student, 'seats', []);

        $this->assertSame(['available_seats'], array_column($result['chips'], 'id'));
    }

    public function test_clicking_a_chip_answers_through_the_suggestion_endpoint_without_any_model_call(): void
    {
        $cgs = $this->cgs();

        $response = $this->actingAs($cgs)->postJson(route('workstation.assistant.suggestion'), [
            'id' => 'release_forecast',
            'params' => ['period' => '2026-12'],
        ]);

        $response->assertOk()
            ->assertJsonPath('outcome', 'answered')
            ->assertJsonPath('interpreted_as', 'How many seats may be released in December 2026?')
            ->assertJsonMissingPath('meta');
        $this->assertSame(0, FakeModel::routeCallCount());
        $this->assertEmpty(FakeModel::answerCalls());
        $this->assertSame(0, FakeModel::aggregateCallCount());
        $this->assertSame(AssistantLog::SOURCE_SUGGESTION, $this->lastLog()->source);
    }

    public function test_a_chip_click_is_validated_like_the_router(): void
    {
        $cgs = $this->cgs();
        $student = $this->eligibleStudent();

        $this->actingAs($cgs)->postJson(route('workstation.assistant.suggestion'), ['id' => 'release_forecast', 'params' => ['period' => 'drop table']])
            ->assertJsonPath('outcome', 'asked_back');

        $this->actingAs($student)->postJson(route('workstation.assistant.suggestion'), ['id' => 'overall_stats'])
            ->assertJsonPath('outcome', 'error');
    }

    public function test_the_ask_endpoint_returns_chips_but_never_the_metadata(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'overall_stats', 'confidence' => 0.3]);

        $this->actingAs($cgs)->postJson(route('workstation.assistant.ask'), ['message' => 'how many seat total'])
            ->assertOk()
            ->assertJsonPath('chips.0.id', 'overall_stats')
            ->assertJsonPath('interpreted_as', 'What are the overall seat totals?')
            ->assertJsonMissingPath('meta');
    }

    public function test_clarify_with_no_usable_id_shows_the_question_and_the_suggested_chips(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'out_of_scope', 'confidence' => 0.2, 'clarify' => 'Do you mean the seat or the locker key?']);

        $result = $this->pipeline()->ask($cgs, 'the seat key thing', []);

        $this->assertSame('asked_back', $result['outcome']);
        $this->assertSame('Do you mean the seat or the locker key?', $result['answer']);
        $this->assertCount(6, $result['suggestions']);
    }

    // -------------------------------------------------------------
    // Follow-ups (browser memory only)
    // -------------------------------------------------------------

    public function test_a_follow_up_is_routed_with_the_masked_recent_questions(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2027-01']]);

        $result = $this->pipeline()->ask($cgs, 'and january?', ['how many seats got freed in dec', 'check 22010001 too']);

        $call = FakeModel::routeCalls()[0];
        $this->assertSame(['how many seats got freed in dec', 'check <ID1> too'], $call['recentMaskedQuestions']);
        $this->assertSame('How many seats may be released in January 2027?', $result['interpreted_as']);
    }

    /** Found live: the router kept December for "and january?" -- the follow-up's own period wins. */
    public function test_a_follow_up_that_names_its_own_period_overrides_a_carried_over_one(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2026-12']]);

        $result = $this->pipeline()->ask($cgs, 'and january?', ['how many seats got freed in dec']);

        $this->assertSame('How many seats may be released in January 2027?', $result['interpreted_as']);
    }

    public function test_only_the_last_five_recent_questions_are_sent(): void
    {
        $cgs = $this->cgs();

        $this->pipeline()->ask($cgs, 'and then?', ['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7']);

        $this->assertSame(['q3', 'q4', 'q5', 'q6', 'q7'], FakeModel::routeCalls()[0]['recentMaskedQuestions']);
    }

    // -------------------------------------------------------------
    // Part 3: exactly what the router call is given
    // -------------------------------------------------------------

    public function test_the_router_payload_contains_only_masked_text_the_catalogue_the_date_and_fixed_material(): void
    {
        $cgs = $this->cgs();
        $student = $this->student('22019988', 'zulaikha.private@test.my', ['name' => 'Zulaikha Testname Binti Ali', 'programme' => 'Doctor of Testology']);
        StudentGender::create(['student_id' => $student->id, 'gender' => 'female']);
        StudyCandidacy::create(['student_id' => $student->id, 'programme_start_date' => now()->subYear(), 'candidacy_expiry_date' => now()->addMonths(2), 'status' => StudyCandidacy::STATUS_ACTIVE]);
        $seat = $this->seat('777', Workstation::STATUS_OCCUPIED, $this->room('J2-09-09', 'J2', 'female'));
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);

        $this->pipeline()->ask($cgs, 'is 22019988 still in seat or not, email zulaikha.private@test.my', []);
        $this->assertSame(0, FakeModel::routeCallCount(), 'An email used as a key never reaches the router.');

        $this->pipeline()->ask($cgs, 'is 22019988 still in seat or not', ['what about 22019988 key', 'J2 room status']);

        $call = FakeModel::routeCalls()[0];
        $this->assertSame('is <ID1> still in seat or not', $call['maskedQuestion']);
        $this->assertSame(['what about <ID1> key', 'J2 room status'], $call['recentMaskedQuestions']);
        $this->assertSame(Catalogue::forRole('cgs', 'workstation'), $call['catalogue']);
        $this->assertSame(RouterFewShots::all(), $call['fewShots']);
        $this->assertSame('Friday, 30 October 2026', $call['todayLabel']);

        // The whole text that would go to Gemini, built from exactly that payload.
        $sent = GeminiModel::routerInstruction($call['catalogue'], $call['fewShots'], $call['todayLabel'], $call['recentMaskedQuestions']).' '.$call['maskedQuestion'];

        foreach (['22019988', 'zulaikha', 'Zulaikha', 'Testname', 'Doctor of Testology', 'J2-09-09', 'test.my'] as $needle) {
            $this->assertStringNotContainsString($needle, $sent, "'{$needle}' reached the router payload.");
        }
        $this->assertDoesNotMatchRegularExpression('/\b\d{8}\b/', $sent);
        $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.-]+/', $sent);
        $this->assertStringContainsString('Today is Friday, 30 October 2026 (Asia/Kuala_Lumpur)', $sent);
        $this->assertStringNotContainsString('### ', $sent, 'No knowledge text is sent to the router -- only one-line topic summaries.');
    }

    public function test_the_http_request_to_gemini_carries_the_role_catalogue_enum_and_nothing_personal(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini.api_key' => 'test-key-not-real', 'assistant.gemini.model' => 'gemini-3.5-flash-lite']);
        Http::fake(['*' => Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"id":"locker_key_request","params":{},"confidence":0.91,"alternatives":[],"clarify":""}']]]]])]);
        $student = $this->eligibleStudent();

        $result = (new Pipeline(new GeminiModel))->ask($student, 'where to take my key, my id 22011111', []);

        // The first request is the router call (the second, if any, is the guidance compose call).
        Http::assertSent(function ($request) {
            $body = $request->data();
            $enum = data_get($body, 'response_format.schema.properties.id.enum');

            if ($enum === null) {
                return false;
            }

            return $body['input'] === 'where to take my key, my id <ID1>'
                && $enum === array_column(Catalogue::forRole('student', 'workstation'), 'id')
                && ! in_array('release_forecast', $enum, true)
                && ! str_contains(json_encode($body), '22011111')
                && isset($body['response_format']['schema']['properties']['params']['properties']['period']);
        });
        $this->assertSame('Where can I get my locker key?', $result['interpreted_as']);
    }

    // -------------------------------------------------------------
    // Part 5: every fallback path
    // -------------------------------------------------------------

    public function test_invalid_json_from_gemini_falls_back_to_the_rules(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.gemini.api_key' => 'test-key-not-real', 'assistant.gemini.model' => 'gemini-3.5-flash-lite', 'assistant.fallback_model' => 'gemini-3.1-flash-lite']);
        Http::fake(['*' => Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Sure! The answer is release_forecast']]]]])]);

        $result = (new Pipeline(new GeminiModel))->ask($this->cgs(), 'how many seat will be release in November 2026?', []);

        $this->assertSame('release_forecast', $this->lastLog()->lookup);
        $this->assertSame(AssistantLog::ANSWERED_BY_RULE_FALLBACK, $this->lastLog()->answered_by);
        $this->assertSame('invalid_response', $this->lastLog()->error_code);
        $this->assertSame('How many seats may be released in November 2026?', $result['interpreted_as']);
    }

    public function test_a_timeout_falls_back_to_the_rules(): void
    {
        $cgs = $this->cgs();
        foreach (range(1, 4) as $i) {
            FakeModel::queueRouteFailure('timeout');
        }

        $result = $this->pipeline()->ask($cgs, 'can u help me to mark 21010121 as key collected?', []);

        $this->assertSame('action_mark_key_collected', $this->lastLog()->lookup);
        $this->assertSame(AssistantLog::ANSWERED_BY_RULE_FALLBACK, $this->lastLog()->answered_by);
        $this->assertSame('timeout', $this->lastLog()->error_code);
        $this->assertSame('refused', $result['outcome']);
        $this->assertStringStartsWith("I can't perform actions", $result['answer']);
    }

    public function test_the_interrogative_guard_keeps_a_release_question_a_data_question_in_the_fallback(): void
    {
        $cgs = $this->cgs();
        FakeModel::forceUnavailable();

        foreach (['how many seat will be release in November 2026?' => '2026-11', 'how many seats got freed in dec' => '2026-12', 'which seat will be free in Q1 2027' => '2027-01..2027-03'] as $question => $period) {
            $result = $this->pipeline()->ask($cgs, $question, []);

            $this->assertSame('release_forecast', $this->lastLog()->lookup, $question);
            $this->assertNotSame('refused', $result['outcome'], $question);
        }
    }

    public function test_the_fallback_resolves_a_period_only_follow_up(): void
    {
        $cgs = $this->cgs();
        FakeModel::forceUnavailable();

        $result = $this->pipeline()->ask($cgs, 'and january?', ['how many seats got freed in dec']);

        $this->assertSame('release_forecast', $this->lastLog()->lookup);
        $this->assertSame('How many seats may be released in January 2027?', $result['interpreted_as']);
    }

    public function test_the_fallback_normalises_a_polite_imperative(): void
    {
        $cgs = $this->cgs();
        $seat = $this->seat('252', Workstation::STATUS_OCCUPIED);
        FakeModel::forceUnavailable();

        $result = $this->pipeline()->ask($cgs, "pls release seat {$seat->id} lah", []);

        $this->assertSame('action_release', $this->lastLog()->lookup);
        $this->assertSame(Pipeline::ACTION_REFUSED_MESSAGE, $result['answer']);
    }

    public function test_the_fallback_offers_keyword_scored_did_you_mean_chips_when_nothing_matches(): void
    {
        $cgs = $this->cgs();
        FakeModel::forceUnavailable();

        $result = $this->pipeline()->ask($cgs, 'seats kena kick soon count', []);

        $this->assertSame('unavailable', $result['outcome']);
        $this->assertSame(['candidacy_expiring', 'seat_availability', 'awaiting_key_return_seats'], array_column($result['chips'], 'id'));
        $this->assertSame(AssistantLog::ANSWERED_BY_RULE_FALLBACK, $this->lastLog()->answered_by);
        $this->assertNotEmpty($result['suggestions'], 'The suggested questions always work without the model.');
    }

    public function test_an_invalid_id_with_no_rule_match_asks_back_with_suggestions(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'nope']);

        $result = $this->pipeline()->ask($cgs, 'qwerty asdf', []);

        $this->assertSame('asked_back', $result['outcome']);
        $this->assertNotEmpty($result['suggestions']);
    }

    // -------------------------------------------------------------
    // The log: metadata only
    // -------------------------------------------------------------

    public function test_the_log_holds_metadata_only(): void
    {
        $cgs = $this->cgs();
        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2026-11'], 'confidence' => 0.9]);
        $this->pipeline()->ask($cgs, 'how many seat will be release in November 2026? ask for 22010055', ['earlier secret question']);

        $row = $this->lastLog()->toArray();

        $this->assertSame('release_forecast', $row['lookup']);
        $this->assertSame('lookup', $row['catalogue_kind']);
        $this->assertSame(AssistantLog::ANSWERED_BY_MODEL_ROUTER, $row['answered_by']);
        foreach (['november', 'November', '22010055', 'secret', 'seats may be released'] as $needle) {
            $this->assertStringNotContainsString($needle, json_encode($row));
        }
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'role', 'domain', 'occurred_at', 'source', 'lookup', 'catalogue_kind', 'outcome', 'refusal_reason', 'result_count', 'answered_by', 'model_used', 'error_code'],
            Schema::getColumnListing('assistant_logs')
        );
    }

    // -------------------------------------------------------------
    // Regressions: the two original questions and the contrast groups
    // -------------------------------------------------------------

    /** Whatever the router decides for each labelled contrast question, our execution of that decision has the right shape -- a lookup or guidance is answered (or asks back), an action is refused and executes nothing, out_of_scope is refused. */
    public function test_every_original_and_contrast_question_executes_its_labelled_entry_correctly(): void
    {
        $cgs = $this->cgs();
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $this->seat('1');
        $rows = collect(require base_path('app/Modules/Chloe/Assistant/evaluation_questions.php'))->whereIn('group', ['original', 'contrast']);

        $this->assertGreaterThanOrEqual(30, $rows->count());
        config(['assistant.rate_limits.default.requests_per_minute' => 1000, 'assistant.rate_limits.default.tokens_per_minute' => 10_000_000]);

        foreach ($rows as $row) {
            FakeModel::reset();
            $params = collect($row['params'] ?? [])->map(fn ($v, $k) => $k === 'student_id' ? '<ID1>' : $v)->all();
            FakeModel::queueRoute(['id' => $row['id'], 'params' => $params, 'confidence' => 0.9]);
            $kind = Catalogue::find($row['id'])['kind'];

            $result = $this->pipeline()->ask($row['role'] === 'cgs' ? $cgs : $student, $row['q'], []);

            $expected = match ($kind) {
                'lookup', 'guidance' => ['answered', 'asked_back'],
                default => ['refused'],
            };
            $this->assertContains($result['outcome'], $expected, $row['q']);
            $this->assertSame($kind, $this->lastLog()->catalogue_kind, $row['q']);
            if ($kind !== 'out_of_scope') {
                $this->assertNotNull($result['interpreted_as'], $row['q']);
            }
        }

        $this->assertSame(1, Workstation::count(), 'No action created or changed anything.');
        $this->assertSame(Workstation::STATUS_AVAILABLE, Workstation::first()->status);
    }

    public function test_the_two_original_questions_never_execute_and_are_labelled_correctly(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueRoute(['id' => 'release_forecast', 'params' => ['period' => '2026-11'], 'confidence' => 0.96]);
        $first = $this->pipeline()->ask($cgs, 'how many seat will be release in November 2026?', []);
        $this->assertSame('answered', $first['outcome']);
        $this->assertSame('How many seats may be released in November 2026?', $first['interpreted_as']);

        FakeModel::queueRoute(['id' => 'action_mark_key_collected', 'params' => ['student_id' => '<ID1>'], 'confidence' => 0.95]);
        $second = $this->pipeline()->ask($cgs, 'can u help me to mark 21010121 as key collected?', []);
        $this->assertSame('refused', $second['outcome']);
        $this->assertSame('Mark the locker key for 21010121 as Collected.', $second['interpreted_as']);
    }

    // -------------------------------------------------------------
    // Role limits
    // -------------------------------------------------------------

    public function test_only_student_approver_and_cgs_can_use_the_assistant(): void
    {
        // the Registry and every other role are still refused; Supervisor, Chair and Dean are the approver role (Study Candidacy)
        $registry = \App\Modules\Core\Models\User::create(['name' => 'Registry', 'email' => 'registry-y@test.my', 'password' => 'password', 'role' => \App\Modules\Core\Support\Role::REGISTRY]);
        $result = $this->pipeline()->ask($registry, 'how many seats are available', []);

        $this->assertSame(Pipeline::ROLE_NOT_SUPPORTED_MESSAGE, $result['answer']);
        $this->assertSame(0, FakeModel::routeCallCount());
    }

    public function test_a_student_only_ever_receives_the_student_catalogue(): void
    {
        $this->pipeline()->ask($this->eligibleStudent(), 'how many seats will be released next month', []);

        $ids = array_column(FakeModel::routeCalls()[0]['catalogue'], 'id');
        $this->assertSame(array_column(Catalogue::forRole('student', 'workstation'), 'id'), $ids);
        foreach ($ids as $id) {
            $this->assertContains('student', Catalogue::find($id)['roles']);
        }
    }

    public function test_a_student_asking_a_cgs_question_gets_the_polite_staff_only_refusal(): void
    {
        $student = $this->eligibleStudent();
        FakeModel::queueRoute(['id' => 'out_of_scope', 'confidence' => 0.8]);

        $result = $this->pipeline()->ask($student, 'how many seats will be released next month', []);

        $this->assertSame('refused', $result['outcome']);
        $this->assertSame(Pipeline::STAFF_ONLY_MESSAGE, $result['answer']);
        $this->assertNotEmpty($result['suggestions']);
    }

    /** Found live: the router picked available_seats for a student's forecast question -- refused instead. */
    public function test_a_student_forecast_question_routed_to_a_student_lookup_is_still_refused(): void
    {
        $student = $this->eligibleStudent();
        FakeModel::queueRoute(['id' => 'available_seats', 'confidence' => 0.85]);

        $result = $this->pipeline()->ask($student, 'how many seats will be released next month', []);

        $this->assertSame(Pipeline::STAFF_ONLY_MESSAGE, $result['answer']);
        $this->assertSame(0, FakeModel::aggregateCallCount());
    }

    // -------------------------------------------------------------
    // The evaluation set itself
    // -------------------------------------------------------------

    public function test_the_evaluation_set_is_well_formed_and_labelled_with_catalogue_ids(): void
    {
        $rows = require base_path('app/Modules/Chloe/Assistant/evaluation_questions.php');

        $this->assertGreaterThanOrEqual(250, count($rows));

        foreach ($rows as $row) {
            $this->assertContains($row['role'], ['student', 'approver', 'cgs']);
            $this->assertContains($row['group'], ['original', 'contrast', 'messy', 'standard', 'followup', 'candidacy']);
            foreach ([$row['id'], ...($row['accept'] ?? [])] as $id) {
                if ($id === 'refused_before_router') {
                    continue; // a person's name: refused before the router, so there is no catalogue entry to check
                }

                $this->assertTrue(Catalogue::isAllowed($id, $row['role']), "'{$row['q']}' is labelled with '{$id}', not in the {$row['role']} catalogue.");
            }
            foreach ($row['id'] === 'refused_before_router' ? [] : array_keys($row['params'] ?? []) as $param) {
                $this->assertArrayHasKey($param, Catalogue::find($row['id'])['parameters'], "'{$row['q']}' expects an undeclared parameter '{$param}'.");
            }
        }
    }

    public function test_assistant_evaluate_report_makes_no_calls(): void
    {
        Http::fake();

        $this->artisan('assistant:evaluate', ['--report' => true])->assertSuccessful();

        $this->assertSame(0, FakeModel::routeCallCount());
        Http::assertNothingSent();
    }
}
