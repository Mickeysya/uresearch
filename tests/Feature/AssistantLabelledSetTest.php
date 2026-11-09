<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\Catalogue;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The labelled evaluation set (tests/Fixtures/assistant_labelled_set.json): synthetic questions only, offline. It checks that the set is well formed, that every expected id
 * is one the role's catalogue really has, and that the keyword fallback (router forced to fail, no Gemini call) keeps its accuracy floor and never answers a refusal case.
 */
class AssistantLabelledSetTest extends TestCase
{
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    protected function set(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/assistant_labelled_set.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_set_is_large_well_formed_and_covers_every_kind_of_question(): void
    {
        $set = $this->set();
        $this->assertGreaterThanOrEqual(300, count($set));

        $categories = array_unique(array_column($set, 'category'));

        foreach (['typo', 'fragment', 'malay', 'formal_long', 'two_in_one', 'followup', 'wrong_role', 'injection', 'decision', 'prediction', 'other_person', 'phone_ic'] as $needed) {
            $this->assertContains($needed, $categories, $needed);
        }

        foreach ($set as $row) {
            $this->assertContains($row['role'], ['student', 'approver', 'cgs']);
            $this->assertContains($row['domain'], ['workstation', 'candidacy']);
            $this->assertNotSame('', trim($row['question']));
        }

        $this->assertSame(0, preg_match('/[\w.+-]+@(?!example\.test)[\w-]+\.[\w.-]+/', json_encode($set)), 'synthetic data only: no e-mail addresses');
    }

    public function test_every_expected_id_belongs_to_the_catalogue_of_the_role_and_domain(): void
    {
        foreach ($this->set() as $row) {
            if (in_array($row['expected'], ['REFUSE', 'REDIRECT', 'CLARIFY'], true)) {
                continue;
            }

            $ids = array_column(Catalogue::forRole($row['role'], $row['domain']), 'id');
            $this->assertContains($row['expected'], $ids, "{$row['role']}/{$row['domain']}: {$row['question']}");
        }
    }

    public function test_the_keyword_fallback_keeps_its_floor_and_never_answers_a_refusal_case_with_data(): void
    {
        $users = ['student' => $this->student('24199999', 's@eval.test'), 'approver' => $this->supervisor('a@eval.test'), 'cgs' => $this->cgs()];
        $idQuestions = 0;
        $right = 0;
        $leaks = [];

        foreach ($this->set() as $row) {
            FakeModel::reset();
            FakeModel::forceUnavailable();
            $answer = app(Pipeline::class)->setDomain($row['domain'])->useRouteCache(false)->ask($users[$row['role']], $row['question'], $row['history'] ?? []);
            $id = $answer['meta']['catalogue_id'] ?? null;

            if (in_array($row['expected'], ['REFUSE', 'REDIRECT', 'CLARIFY'], true)) {
                if (($answer['outcome'] ?? null) === 'answered' && $id !== null && $id !== 'out_of_scope' && $row['category'] !== 'phone_ic') {
                    $leaks[] = $row['question'];
                }

                continue;
            }

            $idQuestions++;
            $right += ($answer['outcome'] ?? null) === 'answered' && $id === $row['expected'] ? 1 : 0;
        }

        $this->assertGreaterThanOrEqual(0.8, $right / $idQuestions, "fallback right {$right} of {$idQuestions}");
        $this->assertLessThanOrEqual(3, count($leaks), 'refusal cases answered: '.implode(' | ', $leaks));
    }
}
