<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\Catalogue;
use App\Modules\Chloe\Assistant\GuidanceLibrary;
use App\Modules\Chloe\Assistant\Lookups;
use App\Modules\Chloe\Assistant\QuestionNormaliser;
use App\Modules\Chloe\Assistant\RouterFewShots;
use App\Modules\Chloe\Assistant\SuggestedQuestions;
use App\Modules\Core\Support\Role;
use Tests\TestCase;

/**
 * Part 1.3: the catalogue must never drift from the code. Fails if a
 * lookup/guidance topic exists in the code without a catalogue entry,
 * or a catalogue entry exists without the code behind it -- both
 * directions, both roles.
 */
class CatalogueTest extends TestCase
{
    public function test_every_cgs_lookup_has_a_catalogue_entry_and_every_lookup_entry_is_a_real_lookup(): void
    {
        $codeLookups = (new \ReflectionClass(Lookups::class))->getMethod('cgsNames');
        $codeLookups->setAccessible(true);
        $cgsLookupNames = $codeLookups->invoke(null);

        $catalogueLookupIds = collect(Catalogue::all())->where('kind', 'lookup')->pluck('id')->all();

        foreach ($cgsLookupNames as $name) {
            $this->assertContains($name, $catalogueLookupIds, "CGS lookup '{$name}' exists in Lookups::cgsNames() but has no catalogue entry.");
        }
        foreach ($catalogueLookupIds as $id) {
            $this->assertTrue(
                Lookups::isAllowed($id, 'cgs') || Lookups::isAllowed($id, Role::STUDENT) || Lookups::isAllowed($id, 'approver'),
                "Catalogue lookup entry '{$id}' is not a real lookup allowed for any role."
            );
        }
    }

    public function test_every_student_lookup_has_a_catalogue_entry(): void
    {
        $codeLookups = (new \ReflectionClass(Lookups::class))->getMethod('studentNames');
        $codeLookups->setAccessible(true);
        $studentLookupNames = $codeLookups->invoke(null);

        $catalogueStudentLookupIds = collect(Catalogue::forRole(Role::STUDENT))->where('kind', 'lookup')->pluck('id')->all();

        foreach ($studentLookupNames as $name) {
            $this->assertContains($name, $catalogueStudentLookupIds, "Student lookup '{$name}' exists in Lookups::studentNames() but has no catalogue entry for the student role.");
        }
    }

    public function test_every_guidance_topic_has_a_catalogue_entry_and_every_guidance_entry_is_a_real_topic(): void
    {
        $allTopics = [...GuidanceLibrary::STUDENT_TOPICS, ...GuidanceLibrary::CGS_TOPICS, ...array_keys(\App\Modules\Chloe\Assistant\CandidacyAssistant::TOPICS)];
        $catalogueGuidanceIds = collect(Catalogue::all())->where('kind', 'guidance')->pluck('id')->all();

        foreach ($allTopics as $topic) {
            $this->assertContains($topic, $catalogueGuidanceIds, "Guidance topic '{$topic}' exists in GuidanceLibrary but has no catalogue entry.");
        }
        foreach ($catalogueGuidanceIds as $id) {
            $this->assertContains($id, $allTopics, "Catalogue guidance entry '{$id}' is not a real GuidanceLibrary topic.");
        }
    }

    public function test_every_catalogue_entry_has_the_required_fields(): void
    {
        foreach (Catalogue::all() as $entry) {
            foreach (['id', 'kind', 'roles', 'question', 'description', 'examples', 'parameters'] as $field) {
                $this->assertArrayHasKey($field, $entry, "Catalogue entry missing '{$field}': ".json_encode($entry));
            }
            $this->assertContains($entry['kind'], ['lookup', 'guidance', 'action', 'out_of_scope']);
            $this->assertNotEmpty($entry['roles'], "Catalogue entry '{$entry['id']}' has no roles.");

            if ($entry['kind'] !== 'out_of_scope') {
                $this->assertNotEmpty($entry['question'], "Catalogue entry '{$entry['id']}' has no canonical question.");
                $this->assertGreaterThanOrEqual(4, count($entry['examples']), "Catalogue entry '{$entry['id']}' has fewer than 4 example paraphrases.");
                $this->assertLessThanOrEqual(6, count($entry['examples']), "Catalogue entry '{$entry['id']}' has more than 6 example paraphrases.");
            }
        }
    }

    /** Part 1.2: every suggested-question chip IS its catalogue entry's canonical question -- same exact text, one wording. */
    public function test_suggested_question_texts_are_their_catalogue_entrys_canonical_question(): void
    {
        foreach (['student', 'cgs'] as $role) {
            foreach (SuggestedQuestions::forRole($role) as $id => $suggestion) {
                $entry = Catalogue::find($suggestion['catalogue_id']);

                $this->assertNotNull($entry, "Suggestion {$id} points at a missing catalogue entry.");
                $this->assertContains($role, $entry['roles'], "Suggestion {$id} points at an entry its own role cannot use.");
                $this->assertSame($suggestion['text'], $entry['question'], "Suggestion {$id}'s text is not its entry's canonical question.");
            }
        }

        $ids = collect([...SuggestedQuestions::forRole('student'), ...SuggestedQuestions::forRole('cgs')])->pluck('catalogue_id');
        $this->assertSame($ids->count(), $ids->unique()->count(), 'Two suggestions point at the same entry -- one canonical question per entry.');
    }

    /** Every action in the code (config('assistant.action_phrases')) is recognised by a catalogue action entry, and every action entry by at least one phrase. */
    public function test_every_action_in_the_code_has_a_catalogue_entry_and_the_reverse(): void
    {
        $phrases = config('assistant.action_phrases');
        $covered = [];

        foreach ($phrases as $phrase) {
            $id = Catalogue::actionIdForText(QuestionNormaliser::normalise("{$phrase} seat 5"), 'cgs')
                ?? Catalogue::actionIdForText(QuestionNormaliser::normalise($phrase), Role::STUDENT);

            $this->assertNotNull($id, "Action phrase '{$phrase}' has no catalogue action entry.");
            $covered[] = $id;
        }

        $actionIds = collect(Catalogue::all())->where('kind', 'action')->pluck('id')->all();
        $this->assertEqualsCanonicalizing(Catalogue::ACTION_KINDS, $actionIds);
        $this->assertEqualsCanonicalizing(Catalogue::ACTION_MATCH_ORDER, $actionIds);

        // the candidacy actions are recognised by their own patterns (the next test), not by the Workstation config('assistant.action_phrases') verbs
        foreach (array_filter($actionIds, fn ($id) => ! str_starts_with($id, 'cand_action_')) as $id) {
            $this->assertContains($id, $covered, "Catalogue action entry '{$id}' is matched by no config('assistant.action_phrases') phrase.");
        }
    }

    /** Each action entry's own examples are recognised as that action by its own pattern (the fallback's imperative path). */
    public function test_each_action_entrys_first_example_matches_its_own_pattern(): void
    {
        foreach (collect(Catalogue::all())->where('kind', 'action') as $entry) {
            $role = in_array('cgs', $entry['roles'], true) ? 'cgs' : Role::STUDENT;

            $this->assertSame($entry['id'], Catalogue::actionIdForText(QuestionNormaliser::normalise($entry['examples'][0]), $role), "'{$entry['examples'][0]}'");
        }
    }

    public function test_every_lookup_entry_names_an_existing_template_and_every_guidance_entry_has_a_summary_from_its_topic(): void
    {
        foreach (Catalogue::all() as $entry) {
            if ($entry['kind'] === 'lookup') {
                $this->assertTrue(method_exists(Lookups::class, $entry['template']) || method_exists(\App\Modules\Chloe\Assistant\CandidacyLookups::class, $entry['template']), "Lookup '{$entry['id']}' names a missing template method.");
            }
            if ($entry['kind'] === 'guidance') {
                $this->assertNotSame('', $entry['summary']);
                $firstWords = implode(' ', array_slice(explode(' ', $entry['summary']), 0, 4));
                $topicText = preg_replace('/\s+/u', ' ', preg_replace('/[*_#`]+/u', '', GuidanceLibrary::sourceText($entry['id'])));
                $this->assertStringContainsString($firstWords, $topicText, "Summary of '{$entry['id']}' is not taken from its topic text.");
            }
        }
    }

    /** Nothing in the router prompt is a real ID, and no evaluation or live-check question is copied into it (except the 4 texts Part 2.2 names). */
    public function test_the_prompt_material_has_no_ids_and_no_evaluation_questions(): void
    {
        $promptQuestions = collect(Catalogue::all())->pluck('examples')->flatten()
            ->merge(collect(RouterFewShots::all())->pluck('question'))
            ->map(fn ($q) => mb_strtolower(trim($q, ' ?.')));

        $this->assertDoesNotMatchRegularExpression('/\b\d{8}\b/', $promptQuestions->implode(' | '));

        $mandated = ['how many seat will be release in november 2026', 'release seat 252', 'can u help me to mark <id1> as key collected', 'how do i release a seat'];
        $evaluation = collect(require base_path('app/Modules/Chloe/Assistant/evaluation_questions.php'))
            ->map(fn ($q) => mb_strtolower(trim(preg_replace('/\b\d{8}\b/', '<ID1>', $q['q']), ' ?.')))
            ->reject(fn ($q) => in_array($q, $mandated, true));

        $this->assertSame([], $evaluation->intersect($promptQuestions)->values()->all(), 'Evaluation questions copied into the prompt.');
    }

    public function test_assistant_catalogue_command_prints_the_catalogue_for_a_role(): void
    {
        $this->artisan('assistant:catalogue', ['role' => 'cgs'])
            ->assertSuccessful()
            ->expectsOutputToContain('release_forecast')
            ->expectsOutputToContain('seat_release_outlook');

        $this->artisan('assistant:catalogue', ['role' => 'student'])
            ->assertSuccessful()
            ->expectsOutputToContain('my_workstation')
            ->doesntExpectOutputToContain('release_forecast');

        $this->artisan('assistant:catalogue', ['role' => 'teacher'])->assertFailed();
    }
}
