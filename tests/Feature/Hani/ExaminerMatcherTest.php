<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Support\Matching\ExaminerSuggestions;
use App\Modules\Hani\Support\Matching\TfIdfMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * The examiner recommendation engine (Support\Matching): TF-IDF over the
 * eligible pool, cosine similarity, and an explanation for every suggestion.
 */
class ExaminerMatcherTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected const TITLE = 'Deep Learning-Based Anomaly Detection for Offshore Pipeline Sensor Data';

    protected User $supervisor;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Department::create(['name' => 'Computing', 'faculty' => 'FSMC']);
        Department::create(['name' => 'Petroleum Engineering', 'faculty' => 'FOE']);

        $this->supervisor = $this->supervisor();
        $this->student = $this->student(attributes: [
            'supervisor_id' => $this->supervisor->id,
            'department' => 'Computing',
        ]);
    }

    protected function examiner(string $name, string $type, array $keywords, array $attributes = []): Examiner
    {
        $examiner = Examiner::create($attributes + [
            'name' => $name,
            'email' => str($name)->slug().'@example.com',
            'department' => 'Computing',
            'type' => $type,
            'institution' => $type === Examiner::TYPE_EXTERNAL ? 'Universiti Malaya' : null,
            'is_active' => true,
        ]);

        foreach ($keywords as $keyword) {
            $examiner->expertiseKeywords()->create(['keyword' => $keyword]);
        }

        return $examiner;
    }

    protected function pool(): array
    {
        return [
            'farah' => $this->examiner('Dr Farah Rosli', Examiner::TYPE_INTERNAL, ['machine learning', 'deep learning', 'anomaly detection', 'time series', 'sensor data']),
            'kevin' => $this->examiner('Dr Kevin Tan', Examiner::TYPE_INTERNAL, ['explainable ai', 'credit scoring', 'decision trees']),
            // Would match well, but is on gap: never suggested.
            'rajesh' => $this->examiner('Prof Rajesh', Examiner::TYPE_INTERNAL, ['anomaly detection', 'sensor data', 'deep learning'], ['last_examination_date' => now()->subDays(10)]),
            // Matches, but internal to another department: never suggested.
            'aisyah' => $this->examiner('Dr Aisyah', Examiner::TYPE_INTERNAL, ['deep learning', 'anomaly detection'], ['department' => 'Petroleum Engineering']),
            'melissa' => $this->examiner('Assoc Prof Melissa Teoh', Examiner::TYPE_EXTERNAL, ['deep learning', 'anomaly detection', 'time series', 'sensor data', 'predictive maintenance']),
            'hassan' => $this->examiner('Prof Hassan Omar', Examiner::TYPE_EXTERNAL, ['concrete', 'geopolymer', 'sustainable materials']),
            // Matches, but marked unavailable: never suggested.
            'gone' => $this->examiner('Dr Retired', Examiner::TYPE_EXTERNAL, ['deep learning', 'anomaly detection'], ['is_active' => false, 'unavailable_reason' => 'Retired']),
        ];
    }

    protected function suggestions(?string $title = self::TITLE): array
    {
        return app(ExaminerSuggestions::class)->forStudent($this->student, $title);
    }

    public function test_only_eligible_examiners_are_ranked_and_the_department_rule_holds(): void
    {
        $pool = $this->pool();
        $result = $this->suggestions();

        $internal = array_column($result['internal'], 'id');
        $external = array_column($result['external'], 'id');

        $this->assertSame($pool['farah']->id, $internal[0], 'The examiner whose expertise fits best comes first.');
        $this->assertNotContains($pool['rajesh']->id, $internal, 'On gap: not nominable, so not suggested.');
        $this->assertNotContains($pool['aisyah']->id, $internal, 'Another department\'s internal examiner is never suggested.');
        $this->assertNotContains($pool['kevin']->id, $internal, 'No shared expertise, no suggestion.');

        $this->assertSame($pool['melissa']->id, $external[0]);
        $this->assertNotContains($pool['gone']->id, $external, 'Unavailable: not suggested.');
        $this->assertNotContains($pool['hassan']->id, $external);
    }

    public function test_every_suggestion_says_why(): void
    {
        $this->pool();
        $farah = $this->suggestions()['internal'][0];

        $this->assertGreaterThan(0, $farah['score']);
        $this->assertLessThanOrEqual(100, $farah['score']);

        // Phrases, in the user's words, standing in for their own words.
        $this->assertContains('anomaly detection', $farah['matched']);
        $this->assertNotContains('anomaly', $farah['matched']);
        $this->assertNotContains('detection', $farah['matched']);

        $this->assertContains('No examination on record', $farah['reasons']);
        $this->assertContains('No examinations in the last 12 months', $farah['reasons']);
    }

    public function test_a_recent_examination_breaks_a_tie_towards_spreading_the_load(): void
    {
        $busy = $this->examiner('Dr Busy', Examiner::TYPE_EXTERNAL, ['anomaly detection', 'sensor data']);
        $idle = $this->examiner('Dr Idle', Examiner::TYPE_EXTERNAL, ['anomaly detection', 'sensor data']);

        // Busy sat on a released panel this year.
        $application = Application::create([
            'student_id' => $this->student('22009998', 'other@test.my')->id,
            'submitted_by_id' => $this->supervisor->id,
            'module_type' => 'examiner_nomination',
            'status' => Application::STATUS_APPROVED,
            'current_stage' => 'cgs_release',
        ]);
        ExaminerNomination::create([
            'application_id' => $application->id,
            'external_main_id' => $busy->id,
            'internal_main_id' => $this->examiner('Dr Someone', Examiner::TYPE_INTERNAL, [])->id,
            'thesis_title' => 'Earlier thesis',
        ]);

        $external = $this->suggestions()['external'];

        $this->assertSame([$idle->id, $busy->id], array_column($external, 'id'));
        $this->assertSame($external[0]['score'], $external[1]['score'], 'Same expertise, same score; the order comes from the tie-breaker.');
        $this->assertContains('1 examination in the last 12 months', $external[1]['reasons']);
    }

    public function test_the_cached_statistics_are_rebuilt_when_expertise_changes(): void
    {
        $pool = $this->pool();
        $matcher = app(TfIdfMatcher::class);

        $before = $matcher->poolVersion();
        $this->assertNotContains($pool['kevin']->id, array_column($this->suggestions()['internal'], 'id'));

        $pool['kevin']->expertiseKeywords()->create(['keyword' => 'anomaly detection']);

        $this->assertNotSame($before, $matcher->poolVersion());
        $this->assertContains($pool['kevin']->id, array_column($this->suggestions()['internal'], 'id'));

        $after = $matcher->poolVersion();
        $pool['kevin']->publications()->create(['title' => 'Sensor streams', 'year' => 2025]);
        $this->assertNotSame($after, $matcher->poolVersion(), 'A publication changes the key too.');
    }

    public function test_no_title_means_no_suggestions_rather_than_a_guess(): void
    {
        $this->pool();

        $result = $this->suggestions(null);

        $this->assertSame([], $result['internal']);
        $this->assertSame('nothing yet', $result['query']['source']);
    }

    public function test_the_endpoint_is_staff_only_and_scoped(): void
    {
        $this->pool();
        $url = route('examiner-nomination.suggestions', ['student_id' => $this->student->id, 'title' => self::TITLE]);

        $this->actingAs($this->supervisor)->getJson($url)->assertOk()
            ->assertJsonPath('internal.0.name', 'Dr Farah Rosli');

        $this->actingAs($this->supervisor('another.sup@test.my'))->getJson($url)->assertForbidden();

        $ae = $this->academicExec();
        $ae->update(['department' => 'Computing']);
        $this->actingAs($ae)->getJson($url)->assertOk();

        $otherAe = $this->user(\App\Modules\Core\Support\Role::ACADEMIC_EXEC, ['email' => 'ae.pet@test.my', 'department' => 'Petroleum Engineering']);
        $this->actingAs($otherAe)->getJson($url)->assertForbidden();

        $this->actingAs($this->seniorExec())->getJson($url)->assertOk();

        // Never the candidate, and never Non-Executive CGS.
        $this->actingAs($this->student)->getJson($url)->assertForbidden();
        $this->actingAs($this->cgs())->getJson($url)->assertForbidden();
    }

    public function test_the_form_carries_the_suggestion_panels(): void
    {
        $this->actingAs($this->supervisor)->get(route('examiner-nomination.create'))
            ->assertOk()
            ->assertSee('data-suggest-url', false)
            ->assertSee('data-suggestions="internal"', false)
            ->assertSee('data-suggestions="external"', false)
            ->assertSee('js/hani/examiner-suggestions.js', false);
    }
}
