<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\GeminiModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Assistant\PrivacyMasker;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * What may leave the system in a question: phone numbers, IC numbers, e-mails and 8-digit student IDs are masked (<PHONE1>, <IC1>, <EMAIL1>, <ID1>),
 * a known student or staff full name stops the question, a role word does not, and the same masking sits again in front of the HTTP call.
 */
class AssistantPrivacyMaskingTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> input => masked text */
    public static function maskCases(): array
    {
        return [
            'mobile, no separators' => ['call me on 0123456789', 'call me on <PHONE1>'],
            'mobile, dash and space' => ['call me on 012-345 6789', 'call me on <PHONE1>'],
            'mobile, +60 with spaces' => ['call me on +60 12-345 6789', 'call me on <PHONE1>'],
            'mobile, 60 prefix' => ['my number 60123456789 ok', 'my number <PHONE1> ok'],
            'mobile, 011 style' => ['011-2345 6789 is mine', '<PHONE1> is mine'],
            'mobile, +6012 dashed' => ['reach me +6012-3456789', 'reach me <PHONE1>'],
            'landline with 8-digit tail' => ['office 03-12345678 please', 'office <PHONE1> please'],
            'a long unbroken run' => ['ref 123456789012345', 'ref <PHONE1>'],
            'IC with dashes' => ['my ic is 900101-14-5678', 'my ic is <IC1>'],
            'IC without dashes' => ['my ic is 900101145678', 'my ic is <IC1>'],
            'IC with one dash' => ['ic 900101-145678', 'ic <IC1>'],
            'an 8-digit student ID is still an ID' => ['status of student 24100215', 'status of student <ID1>'],
            'two IDs side by side stay two IDs' => ['compare 24100215 24100216', 'compare <ID1> <ID2>'],
            'ID and phone in one question' => ['0123456789 and 24100215', '<PHONE1> and <ID1>'],
            'ID, IC and phone together' => ['id 24100215 ic 900101-14-5678 tel 012-345 6789', 'id <ID1> ic <IC1> tel <PHONE1>'],
            'the e-mail rule is unchanged' => ['my matric is 24100215 email ainasofeayusof_24100215@utp.edu.my when can i appeal', 'my matric is <ID1> email <EMAIL1> when can i appeal'],
            'seat numbers are not phone numbers' => ['seat 252 253 254', 'seat 252 253 254'],
            'a short list of numbers is not a phone number' => ['seats 12 13 14 15 16', 'seats 12 13 14 15 16'],
            'an ISO date and time are not a phone number' => ['released on 2026-11-09 10:30', 'released on 2026-11-09 10:30'],
            'a day-first date is not a phone number' => ['expires 09-11-2026 10', 'expires 09-11-2026 10'],
            'a plain seat question is untouched' => ['status of seat 252', 'status of seat 252'],
        ];
    }

    /** @dataProvider maskCases */
    public function test_the_masked_text(string $input, string $expected): void
    {
        $this->assertSame($expected, PrivacyMasker::mask($input)['masked']);
    }

    public function test_only_ids_and_emails_can_be_unmasked_phone_and_ic_placeholders_are_one_way(): void
    {
        $m = PrivacyMasker::mask('id 24100215 mail a@b.my ic 900101-14-5678 tel 0123456789');

        $this->assertSame(['<EMAIL1>' => 'a@b.my', '<ID1>' => '24100215'], $m['idMap']);
        $this->assertSame('<PHONE1>', PrivacyMasker::unmask('<PHONE1>', $m['idMap']));
        $this->assertSame('<IC1>', PrivacyMasker::unmask('<IC1>', $m['idMap']));
    }

    public function test_the_masking_is_idempotent(): void
    {
        $once = PrivacyMasker::mask('call 0123456789 id 24100215 ic 900101145678')['masked'];

        $this->assertSame($once, PrivacyMasker::scrub($once));
    }

    protected function staff(): void
    {
        $this->user(Role::SUPERVISOR, ['email' => 'sup@test.my', 'name' => 'Dr. Aisyah Rahman']);
        $this->user(Role::CHAIR, ['email' => 'chair@test.my', 'name' => 'Dr. Lim Wei Chun']);
        $this->user(Role::DEAN_PGR, ['email' => 'dean@test.my', 'name' => 'Prof. Dr. Hafiz Osman']);
        $this->user(Role::NON_EXEC_CGS, ['email' => 'cgs@test.my', 'name' => 'M Syahmi Ifwat M Jafri']);
        CandidacyStaffProfile::create(['full_name' => 'Prof. Ir. Dr. Ng Yen Ni', 'designation' => 'Chair', 'is_demo' => 1]);
    }

    public function test_a_known_staff_full_name_is_recognised_with_or_without_titles(): void
    {
        $this->staff();

        foreach (['where is Dr. Aisyah Rahman', 'aisyah rahman email please', 'EMAIL FOR PROF DR HAFIZ OSMAN?', 'is lim wei chun the chair', 'phone of m syahmi ifwat m jafri', 'what about Ng Yen Ni'] as $text) {
            $this->assertTrue(PrivacyMasker::containsKnownStaffName($text), $text);
        }
    }

    public function test_role_words_and_single_first_names_are_not_refused(): void
    {
        $this->staff();

        foreach (['who is my supervisor', 'how do i email my supervisor', 'what does the Dean do', 'when does the chair approve', 'aisyah', 'hafiz', 'how do i contact cgs', 'my supervisor aisyah is away'] as $text) {
            $this->assertFalse(PrivacyMasker::containsKnownStaffName($text), $text);
        }
    }

    protected ?\App\Modules\Core\Models\User $asker = null;

    protected function ask(string $text, array $history = [], string $domain = 'candidacy')
    {
        $student = $this->asker ??= $this->student('24100215', 'ainasofeayusof_24100215@test.my');
        $pipeline = new Pipeline(new FakeModel);
        $pipeline->useRouteCache(false);
        $pipeline->setDomain($domain);
        FakeModel::reset();

        return [$pipeline->ask($student, $text, $history), FakeModel::routeCallCount()];
    }

    public function test_a_staff_name_gets_the_privacy_message_and_nothing_goes_to_the_router(): void
    {
        $this->staff();

        foreach (['where is Dr. Aisyah Rahman', 'give me the phone of Prof Dr Hafiz Osman'] as $text) {
            [$answer, $routerCalls] = $this->ask($text);

            $this->assertSame('refused', $answer['outcome']);
            $this->assertSame(Pipeline::PRIVACY_NAME_MESSAGE, $answer['answer']);
            $this->assertSame(0, $routerCalls, 'no model call for '.$text);
        }
    }

    public function test_my_supervisor_and_the_dean_are_still_allowed_through_to_the_router(): void
    {
        $this->staff();

        foreach (['who is my supervisor', 'what does the Dean do'] as $text) {
            [$answer, $routerCalls] = $this->ask($text);

            $this->assertNotSame(Pipeline::PRIVACY_NAME_MESSAGE, $answer['answer'] ?? null);
            $this->assertSame(1, $routerCalls, 'the router is called for '.$text);
        }
    }

    public function test_history_is_masked_and_an_earlier_question_naming_someone_is_dropped(): void
    {
        $this->staff();

        $this->assertSame(
            ['call <PHONE1>', 'my ic <IC1>', 'student <ID1>'],
            PrivacyMasker::maskHistory(['call 0123456789', 'where is Dr. Aisyah Rahman', 'my ic 900101-14-5678', 'student 24100215'])
        );
    }

    /** The real GeminiModel and the real Pipeline, the HTTP call stubbed: what would have been sent. */
    protected function capture(callable $run): string
    {
        config(['assistant.gemini.api_key' => 'stub-key', 'assistant.gemini.model' => 'stub-model', 'assistant.fallback_model' => 'stub-fallback', 'assistant.provider' => 'gemini']);
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->body();

            return Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode(['id' => 'out_of_scope', 'params' => (object) [], 'confidence' => 0.9, 'alternatives' => [], 'clarify' => ''])]]]]], 200);
        });
        $run();

        $this->assertNotEmpty($sent, 'a request was captured');

        return implode("\n", $sent);
    }

    public function test_the_captured_gemini_request_holds_none_of_the_raw_inputs(): void
    {
        $this->staff();
        $student = $this->student('24100215', 'ainasofeayusof_24100215@test.my');

        $body = $this->capture(function () use ($student) {
            $pipeline = new Pipeline(new GeminiModel);
            $pipeline->useRouteCache(false);
            $pipeline->setDomain('candidacy');
            $pipeline->ask($student, 'when can i appeal, call me on 012-345 6789, my ic is 900101-14-5678, student 24100216', [
                'my number is 0123456789',
                'ic 900101145678',
                'where is Dr. Aisyah Rahman',
                'student 24100217 asked',
            ]);
        });

        foreach (['012-345 6789', '0123456789', '900101-14-5678', '900101145678', '24100216', '24100217', 'Aisyah', 'aisyah'] as $raw) {
            $this->assertStringNotContainsString($raw, $body, "the request must not contain {$raw}");
        }

        foreach (['<PHONE1>', '<IC1>', '<ID1>'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $body);
        }
    }

    public function test_the_compose_call_is_masked_too(): void
    {
        config(['assistant.gemini.api_key' => 'stub-key']);
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->body();

            return Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'ok']]]]], 200);
        });

        (new GeminiModel)->composeAggregate('how many seats, call me 0123456789 ic 900101-14-5678 id 24100215', 'seat_availability', ['text' => 'room 900101145678 tel 012-345 6789', 'resultCount' => 3], 'stub-model', 5);

        $this->assertCount(1, $sent);

        foreach (['0123456789', '900101-14-5678', '900101145678', '012-345 6789', '24100215'] as $raw) {
            $this->assertStringNotContainsString($raw, $sent[0]);
        }
        $this->assertStringContainsString('<PHONE1>', $sent[0]);
        $this->assertStringContainsString('<IC1>', $sent[0]);
    }

    public function test_the_guidance_call_is_masked_too(): void
    {
        config(['assistant.gemini.api_key' => 'stub-key']);
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->body();

            return Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode(['kind' => 'guidance', 'answer' => 'x', 'sources' => [], 'lookup' => null, 'params' => (object) []])]]]]], 200);
        });

        (new GeminiModel)->answer('how do i select a seat call 0123456789', ['earlier ic 900101-14-5678'], '### select_seat\nText', [], 'stub-model', 5);

        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('0123456789', $sent[0]);
        $this->assertStringNotContainsString('900101-14-5678', $sent[0]);
    }
}
