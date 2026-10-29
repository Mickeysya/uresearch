<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\GuidanceLibrary;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Round 3 PART B (complete system knowledge) and PART C (topic hints and
 * accuracy) -- loading per role, the always-included glossary/timeline
 * guarantee, and the four required regression questions.
 */
class WorkstationAssistantKnowledgeTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakeAssistant();
        config(['assistant.gemini.model' => 'gemini-3.5-flash-lite', 'assistant.fallback_model' => 'gemini-3.1-flash-lite']);
    }

    protected function pipeline(): Pipeline
    {
        return app(Pipeline::class);
    }

    // -------------------------------------------------------------
    // PART B: knowledge loads per role
    // -------------------------------------------------------------

    public function test_a_student_never_receives_a_cgs_only_topics_text(): void
    {
        $knowledge = GuidanceLibrary::knowledgeTextForRole('student');

        foreach (GuidanceLibrary::CGS_TOPICS as $cgsTopic) {
            $this->assertStringNotContainsString("### {$cgsTopic}", $knowledge['text']);
        }
    }

    public function test_cgs_receives_both_cgs_and_student_topics(): void
    {
        $knowledge = GuidanceLibrary::knowledgeTextForRole('cgs');

        $this->assertStringContainsString('### cgs_system_guide', $knowledge['text']);
        $this->assertStringContainsString('### student_system_guide', $knowledge['text']);
    }

    public function test_every_new_topic_file_actually_exists_and_loads(): void
    {
        foreach (['student_system_guide', 'student_status_glossary', 'student_timeline'] as $topic) {
            $this->assertNotNull(GuidanceLibrary::sourceText($topic), "{$topic} did not load.");
        }

        foreach (['cgs_system_guide', 'cgs_status_glossary', 'cgs_automation', 'cgs_insights_definitions', 'cgs_assistant_scope'] as $topic) {
            $this->assertNotNull(GuidanceLibrary::sourceText($topic), "{$topic} did not load.");
        }
    }

    /** PART B.3: the glossary/timeline topics survive even when the cap forces the keyword-scoring fallback to drop most others. */
    public function test_glossary_and_timeline_topics_are_always_included_even_under_a_tight_cap(): void
    {
        config(['assistant.max_knowledge_chars' => 6000]);

        $student = GuidanceLibrary::knowledgeTextForRole('student', 'something about opening hours');
        $this->assertContains('student_status_glossary', $student['topicsIncluded']);
        $this->assertContains('student_timeline', $student['topicsIncluded']);

        $cgs = GuidanceLibrary::knowledgeTextForRole('cgs', 'something about opening hours');
        $this->assertContains('cgs_status_glossary', $cgs['topicsIncluded']);
    }

    public function test_the_full_student_set_is_under_25000_characters(): void
    {
        $knowledge = GuidanceLibrary::knowledgeTextForRole('student');
        $this->assertLessThan(25000, $knowledge['fullChars']);
    }

    public function test_the_full_cgs_set_is_under_45000_characters(): void
    {
        $knowledge = GuidanceLibrary::knowledgeTextForRole('cgs');
        $this->assertLessThan(45000, $knowledge['fullChars']);
    }

    // -------------------------------------------------------------
    // PART C.2: the four required regression questions
    // -------------------------------------------------------------

    public function test_confirm_in_time_gives_the_confirmation_topic(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Confirm within the window or the seat is released.', 'sources' => ['confirmation']]);

        $this->pipeline()->ask($student, 'What happens if I do not confirm in time?', []);

        $this->assertSame('guidance:confirmation', AssistantLog::latest('occurred_at')->first()->lookup);
        // The keyword rule's own match ("confirm"+"in time") is passed as a hint, not skipped.
        $this->assertContains('confirmation', FakeModel::answerCalls()[0]['topicHints']);
    }

    public function test_collect_my_locker_key_gives_the_collect_return_topic(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'Collect it at CGS.', 'sources' => ['locker_key_collect_return']]);

        $this->pipeline()->ask($student, 'How do I collect my locker key?', []);

        $this->assertSame('guidance:locker_key_collect_return', AssistantLog::latest('occurred_at')->first()->lookup);
    }

    public function test_am_i_eligible_gives_the_eligibility_topic(): void
    {
        $student = $this->eligibleStudent();

        FakeModel::queueAnswer(['kind' => 'guidance', 'answer' => 'You must be Active, full-time research, not expired.', 'sources' => ['eligibility_loan_period']]);

        $this->pipeline()->ask($student, 'Am I eligible for a workstation?', []);

        $this->assertSame('guidance:eligibility_loan_period', AssistantLog::latest('occurred_at')->first()->lookup);
    }

    /** "one student holds only one seat; Reassign moves a student in one step; Assign Student is only for a student with no seat" -- answered from BOTH cgs_reassign and cgs_assign. */
    public function test_choosing_another_seat_for_an_unreleased_student_cites_reassign_and_assign(): void
    {
        $cgs = $this->cgs();

        FakeModel::queueAnswer([
            'kind' => 'guidance',
            'answer' => 'One student holds only one seat. Use Reassign to move the student to the new seat in one step -- Assign Student only works for a student with no seat at all.',
            'sources' => ['cgs_reassign', 'cgs_assign'],
        ]);

        $result = $this->pipeline()->ask($cgs, 'can i choose another seat for a student when the previous one not release?', []);

        $this->assertSame('answered', $result['outcome']);
        $log = AssistantLog::latest('occurred_at')->first();
        $this->assertSame('guidance:cgs_reassign', $log->lookup);
        $this->assertStringContainsString('Reassign', $result['answer']);
        $this->assertStringContainsString('Assign Student', $result['answer']);
    }
}
