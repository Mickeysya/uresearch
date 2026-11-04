<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\AssistantRoles;
use App\Modules\Chloe\Assistant\CandidacyAssistant;
use App\Modules\Chloe\Assistant\Catalogue;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\GuidanceLibrary;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Assistant\QuestionNormaliser;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Answers depend on the role: the intent x role matrix, no instruction written for another role ever reaches a role (model, keyword fallback or nothing matched),
 * the Handbook out-of-scope refusal, and the request counter.
 */
class CandidacyAssistantRolesTest extends TestCase
{
    use BuildsCandidacyWorld {
        setUp as protected worldSetUp;
    }
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->worldSetUp();
        $this->setUpFakeAssistant();
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-01', 'original_expiry_date' => '2027-03-01']);
    }

    protected function pipeline(): Pipeline
    {
        return app(Pipeline::class)->setDomain('candidacy');
    }

    protected function userFor(string $role): User
    {
        return match ($role) {
            'student' => $this->student,
            'approver' => $this->lecturer,
            'cgs' => $this->cgsUser,
        };
    }

    protected function assertNoForbidden(string $role, string $text, string $label): void
    {
        foreach (CandidacyAssistant::FORBIDDEN[$role] as $term) {
            $hit = str_starts_with($term, '/') ? preg_match($term, $text) === 1 : str_contains($text, $term);
            $this->assertFalse($hit, "{$label}: a {$role} answer mentions \"{$term}\"");
        }
    }

    public function test_the_matrix_has_at_least_thirty_intents_and_every_cell_is_an_answer_or_an_explicit_not_for_this_role(): void
    {
        $intents = array_merge(array_keys(CandidacyAssistant::topicRoles()), array_column(array_filter(Catalogue::all(), fn ($e) => $e['domain'] === 'candidacy' && $e['kind'] === 'lookup'), 'id'));
        $this->assertGreaterThanOrEqual(30, count(array_unique($intents)));

        foreach (array_keys(CandidacyAssistant::topicRoles()) as $topic) {
            foreach (['student', 'approver', 'cgs'] as $role) {
                if (in_array($role, CandidacyAssistant::topicRoles()[$topic], true)) {
                    $text = CandidacyAssistant::sourceText($topic, $role);
                    $this->assertNotNull($text, "{$topic} for {$role}");
                    $this->assertMatchesRegularExpression('/^Source: .+/m', $text, "{$topic} for {$role}");
                    $this->assertDoesNotMatchRegularExpression('/verify page|\{\{/i', $text, "{$topic} for {$role}");
                    $this->assertNoForbidden($role, $text, "{$topic} for {$role}");
                } else {
                    // not for this role: the role is never given it, and asking for it gets the redirect text, not another role's answer
                    $this->assertFalse(GuidanceLibrary::isAllowed($topic, $role, 'candidacy'), "{$topic} must not be allowed for {$role}");
                    $this->assertNull(CandidacyAssistant::sourceText($topic, $role), "{$topic} must have no text for {$role}");
                }
            }
        }
    }

    public function test_the_role_variants_say_different_things(): void
    {
        $student = CandidacyAssistant::sourceText('cand_see_all_appeals', 'student');
        $approver = CandidacyAssistant::sourceText('cand_see_all_appeals', 'approver');
        $cgs = CandidacyAssistant::sourceText('cand_see_all_appeals', 'cgs');

        $this->assertStringContainsString('My Appeals', $student);
        $this->assertStringContainsString('Pending my action', $approver);
        $this->assertStringContainsString('Operations', $cgs);
        $this->assertStringContainsString('View all appeals', $cgs);
        $this->assertStringNotContainsString('Submit an Appeal', $cgs.$approver);
    }

    public function test_the_failing_cgs_question_in_model_mode(): void
    {
        FakeModel::queueRoute(['id' => 'cand_see_all_appeals']);
        $result = $this->pipeline()->ask($this->cgsUser, 'how can i see the full candidacy appeal list?', []);

        $this->assertSame('answered', $result['outcome']);
        $this->assertStringContainsString('Operations', $result['answer']);
        $this->assertStringNotContainsString('New appeal', $result['answer']);
        $this->assertStringNotContainsString('Submit an Appeal', $result['answer']);
        $this->assertSame('cand_see_all_appeals', $result['meta']['catalogue_id'] ?? null);
    }

    public function test_the_failing_cgs_question_in_keyword_fallback_mode(): void
    {
        FakeModel::forceUnavailable();
        $result = $this->pipeline()->ask($this->cgsUser, 'how can i see the full candidacy appeal list?', []);

        $this->assertSame('answered', $result['outcome']);
        $this->assertSame('cand_see_all_appeals', $result['meta']['catalogue_id'] ?? null);
        $this->assertStringContainsString('Operations', $result['answer']);
        $this->assertStringNotContainsString('Submit an Appeal', $result['answer']);
        $this->assertStringNotContainsString('Handbook says: a student', $result['answer']);
    }

    public function test_the_failing_cgs_question_when_nothing_matches(): void
    {
        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $result = $this->pipeline()->ask($this->cgsUser, 'how can i see the full candidacy appeal list?', []);

        $this->assertNotSame('answered', $result['outcome']);
        $this->assertStringContainsString('I could not match that', $result['answer']);
        $this->assertNotEmpty($result['suggestions'] ?? []);
        $this->assertStringNotContainsString('New appeal', $result['answer']);
    }

    public function test_the_same_words_get_the_answer_written_for_each_role_in_fallback_mode(): void
    {
        FakeModel::forceUnavailable();
        $q = 'where can i see all the appeals';

        $student = $this->pipeline()->ask($this->student, $q, []);
        $approver = $this->pipeline()->ask($this->lecturer, $q, []);
        $cgs = $this->pipeline()->ask($this->cgsUser, $q, []);

        $this->assertStringContainsString('My Appeals', $student['answer']);
        $this->assertStringContainsString('Pending my action', $approver['answer']);
        $this->assertStringContainsString('Operations', $cgs['answer']);
        $this->assertNoForbidden('student', $student['answer'], 'student');
        $this->assertNoForbidden('approver', $approver['answer'], 'approver');
    }

    public function test_the_keyword_fallback_only_ever_answers_with_the_asking_roles_own_entries(): void
    {
        $phrases = [];

        foreach (Catalogue::all() as $entry) {
            if ($entry['domain'] === 'candidacy') {
                $phrases = [...$phrases, $entry['question'], ...$entry['examples']];
            }
        }

        $phrases = [...$phrases, 'how do i appeal for an extension', 'how can i see the full candidacy appeal list', 'how do i approve an appeal', 'what happens to my appeal', 'how to upload the registry file'];

        foreach (['student', 'approver', 'cgs'] as $role) {
            $own = array_column(Catalogue::forRole($role, 'candidacy'), 'id');

            foreach ($phrases as $phrase) {
                $id = CandidacyAssistant::keywordMatch(QuestionNormaliser::normalise($phrase), $role);
                $this->assertTrue($id === null || in_array($id, $own, true), "\"{$phrase}\" gave {$role} the entry {$id}, which {$role} does not have");
            }
        }
    }

    public function test_how_to_appeal_is_never_answered_to_an_approver_or_vague_questions_to_anyone(): void
    {
        FakeModel::forceUnavailable();

        $approver = $this->pipeline()->ask($this->lecturer, 'how do i appeal for an extension', []);
        $this->assertNotSame('answered', $approver['outcome']);
        $this->assertStringContainsString('That is for students', $approver['answer']);

        foreach (['student', 'approver', 'cgs'] as $role) {
            $vague = $this->pipeline()->ask($this->userFor($role), 'tell me something about appeals', []);
            $this->assertNotSame('answered', $vague['outcome'], "a vague question was answered for {$role}");
        }
    }

    public function test_a_question_for_another_roles_page_is_redirected_to_what_this_role_can_ask(): void
    {
        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $student = $this->pipeline()->ask($this->student, 'how do i read the operations page', []);

        $this->assertStringContainsString('That is for CGS', $student['answer']);
        $this->assertNotEmpty($student['suggestions'] ?? []);

        FakeModel::queueRoute(['id' => 'out_of_scope']);
        $approver = $this->pipeline()->ask($this->lecturer, 'where is the dismissal list', []);
        $this->assertStringContainsString('That is for CGS', $approver['answer']);
    }

    public function test_handbook_topics_outside_study_candidacy_are_refused_without_a_model_call(): void
    {
        foreach (['when is my rpd deadline', 'how do i apply for thesis correction extension', 'what is my ga allowance', 'how to change my supervisor', 'how much are the fees', 'can i upgrade from master to phd', 'what is the viva category', 'how do i apply for annual leave'] as $question) {
            $calls = FakeModel::routeCallCount();
            $result = $this->pipeline()->ask($this->student, $question, []);

            $this->assertSame('refused', $result['outcome'], $question);
            $this->assertStringContainsString('Study Candidacy', $result['answer'], $question);
            $this->assertSame($calls, FakeModel::routeCallCount(), "{$question} reached the model");
        }

        // a candidacy word keeps it in scope
        FakeModel::queueRoute(['id' => 'cand_if_expires']);
        $this->assertSame('answered', $this->pipeline()->ask($this->student, 'what happens to my candidacy if i have no visa', [])['outcome']);
    }

    public function test_every_role_has_at_least_eight_suggestions_and_each_is_answered_without_a_model_call(): void
    {
        foreach (['student', 'approver', 'cgs'] as $role) {
            $suggestions = CandidacyAssistant::suggestions($role);
            $this->assertGreaterThanOrEqual(8, count($suggestions), $role);

            foreach ($suggestions as $id => $text) {
                $this->assertTrue(Catalogue::isAllowed($id, $role), "{$id} suggested to {$role}");
                $calls = FakeModel::routeCallCount();
                $result = $this->pipeline()->ask($this->userFor($role), $text, []);

                $this->assertSame('answered', $result['outcome'], "{$role}: {$text}");
                $this->assertSame($calls, FakeModel::routeCallCount(), "{$role}: {$text} used the model");

                if (($result['meta']['catalogue_kind'] ?? '') === 'guidance') {
                    $this->assertNoForbidden($role, $result['answer'], "{$role}: {$text}");
                }
            }
        }
    }

    public function test_data_answers_never_contain_another_users_data(): void
    {
        FakeModel::queueRoute(['id' => 'cand_my_expiry']);
        $answer = $this->pipeline()->ask($this->student, 'when does my candidacy expire', [])['answer'];

        $this->assertStringContainsString('1 Mar 2027', $answer);
        $this->assertStringNotContainsString($this->cgsUser->name, $answer);
        $this->assertStringNotContainsString($this->lecturer->name, $answer);
    }

    public function test_the_request_counter_counts_only_requests_that_were_sent(): void
    {
        config(['assistant.gemini.model' => 'test-primary', 'assistant.fallback_model' => 'test-fallback']);
        $day = 'assistant:calls:day:'.config('assistant.gemini.model').':'.now(config('assistant.day_timezone'))->format('Ymd');

        // no key configured: nothing leaves, so nothing is counted (every attempt is taken back)
        FakeModel::queueRouteFailure('no_credentials');
        FakeModel::queueRouteFailure('no_credentials');
        FakeModel::queueRouteFailure('no_credentials');
        $this->pipeline()->ask($this->student, 'when does my candidacy end', []);
        $this->assertSame(0, (int) Cache::get($day, 0));

        // a real attempt that failed was sent, so it counts (the failures above opened the circuit breaker; start clean)
        Cache::flush();
        FakeModel::reset();
        FakeModel::queueRouteFailure('http_500');
        FakeModel::queueRouteFailure('http_500');
        FakeModel::queueRouteFailure('http_500');
        $this->pipeline()->ask($this->student, 'when does my candidacy end', []);
        $this->assertGreaterThanOrEqual(1, (int) Cache::get($day, 0));
    }

    public function test_every_page_and_button_the_answers_name_exists_in_the_views(): void
    {
        $views = '';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app/Modules/Chloe/Resources/views/candidacy'))) as $file) {
            $views .= $file->isFile() ? file_get_contents($file->getPathname()) : '';
        }

        $views .= file_get_contents(base_path('app/Modules/Core/Resources/views/partials/sidebar-student-nav.blade.php'));

        foreach (['Submit an Appeal', 'Edit and resubmit', 'Download memo', 'Return to student', 'Reject (final)', 'Confirm rejection', 'Save signature', 'Remove my signature', 'Pending my action', 'Decided by me', 'View all appeals',
            'My Appeals', 'Study Candidacy Approval Portal', 'Study Candidacy Management', 'Study Candidacy Insights', 'Dismissal List', 'Dismissal Upload', 'Confirm and dismiss', 'Preview', 'Review', 'Approve', 'Verify', 'Track My Applications', 'My Study Candidacy',
            'Department and Supervisor / Programme Manager', 'Research and extension', 'Documents', 'Review and submit', 'Section A: Research progress', 'Section B: Earlier extensions', 'Section C: Publications', 'Section D: Extension requested',
            'Pause updates', 'Waiting At Least', 'Months Requested', 'Export filtered list to Excel'] as $label) {
            $this->assertStringContainsString($label, $views, "The answers name \"{$label}\" but no candidacy view has it.");
        }
    }
}
