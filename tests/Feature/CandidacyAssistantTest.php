<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\AssistantRoles;
use App\Modules\Chloe\Assistant\CandidacyAssistant;
use App\Modules\Chloe\Assistant\Catalogue;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Models\AssistantLog;
use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Support\CandidacyDismissalList;
use App\Modules\Chloe\Support\CandidacyOperationsQuery;
use App\Modules\Chloe\Support\CandidacyPortalQuery;
use App\Modules\Chloe\Support\CandidacyStatistics;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** The Study Candidacy part of the assistant: role isolation, privacy, refusals, numbers equal to the pages, topic sources. */
class CandidacyAssistantTest extends TestCase
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

    protected function ask(User $user, string $question, ?string $routeTo = null, array $params = []): array
    {
        if ($routeTo !== null) {
            FakeModel::queueRoute(['id' => $routeTo, 'params' => $params]);
        }

        return app(Pipeline::class)->ask($user, $question, []);
    }

    protected function userFor(string $role): User
    {
        return match ($role) {
            'student' => $this->student,
            'approver' => $this->lecturer,
            'cgs' => $this->cgsUser,
            'cgs_approver' => tap($this->cgsUser, function ($u) {
                $profile = CandidacyStaffProfile::create(['user_id' => $u->id, 'full_name' => 'CGS Who Also Approves', 'designation' => 'Lecturer', 'department_id' => $this->candidacy->department_id]);
                CandidacyStaffAssignment::create(['department_id' => $this->candidacy->department_id, 'staff_profile_id' => $profile->id, 'role' => CandidacyStaffAssignment::ROLE_LECTURER]);
            }),
        };
    }

    public static function roles(): array
    {
        return ['student' => ['student'], 'approver' => ['approver'], 'cgs' => ['cgs'], 'cgs who is also an approver' => ['cgs_approver']];
    }

    /** @dataProvider roles */
    public function test_every_role_against_every_candidacy_entry_nobody_gets_another_roles_entry(string $role): void
    {
        $user = $this->userFor($role);
        $this->assertSame($role, AssistantRoles::roleFor($user));
        $expected = array_merge(...array_map(fn ($plain) => array_keys(array_filter(CandidacyAssistant::TOPICS, fn ($r) => in_array($plain, $r, true))), AssistantRoles::set($role)));

        foreach (Catalogue::all() as $entry) {
            if ($entry['domain'] !== 'candidacy') {
                continue;
            }

            $allowed = AssistantRoles::covers($role, $entry['roles']);
            $this->assertSame($allowed, Catalogue::isAllowed($entry['id'], $role), "{$entry['id']} for {$role}");
            $this->assertSame($allowed, in_array($entry['id'], array_column(Catalogue::forRole($role), 'id'), true), "{$entry['id']} listed for {$role}");

            // the model naming an entry the role does not own never answers with it
            $result = $this->ask($user, 'tell me about my candidacy appeal', $entry['id']);
            $answeredWith = $result['meta']['catalogue_id'] ?? null;

            if (! $allowed) {
                $this->assertNotSame($entry['id'], $answeredWith, "{$role} was answered with {$entry['id']}");
            }
        }

        foreach (CandidacyAssistant::TOPICS as $topic => $roles) {
            $this->assertSame(in_array($topic, $expected, true), \App\Modules\Chloe\Assistant\GuidanceLibrary::isAllowed($topic, $role), "topic {$topic} for {$role}");
        }
    }

    public function test_the_catalogue_has_the_required_number_of_entries_per_role(): void
    {
        $count = fn (string $role) => count(array_filter(Catalogue::forRole($role), fn ($e) => $e['domain'] === 'candidacy' && $e['kind'] !== 'action'));

        $this->assertGreaterThanOrEqual(18, $count('student'));
        $this->assertGreaterThanOrEqual(12, $count('approver'));
        $this->assertGreaterThanOrEqual(35, $count('cgs'));
    }

    public function test_a_student_only_reads_their_own_record(): void
    {
        $other = $this->user(\App\Modules\Core\Support\Role::STUDENT, ['name' => 'Other Student', 'email' => 'other@test.my', 'matric_no' => '22009999']);
        \App\Modules\Chloe\Models\StudyCandidacy::create(['student_id' => $other->id, 'department_id' => $this->candidacy->department_id, 'programme_start_date' => '2024-01-10', 'candidacy_expiry_date' => '2031-05-05', 'original_expiry_date' => '2031-05-05', 'status' => 'active']);

        $mine = $this->ask($this->student, 'when does my candidacy end', 'cand_my_expiry');
        $theirs = $this->ask($other, 'when does my candidacy end', 'cand_my_expiry');

        $this->assertStringContainsString('1 Mar 2027', $mine['answer']);
        $this->assertStringNotContainsString('2031', $mine['answer']);
        $this->assertStringContainsString('5 May 2031', $theirs['answer']);
        $this->assertStringNotContainsString('2027', $theirs['answer']);
    }

    public function test_the_student_numbers_are_the_page_numbers(): void
    {
        $this->candidacy->update(['extension_months_used' => 4, 'cumulative_extension_months' => 4]);
        $answer = $this->ask($this->student, 'how many extension months left', 'cand_my_extension')['answer'];

        $this->assertStringContainsString('used 4 of 12', $answer);
        $this->assertStringContainsString($this->candidacy->fresh()->remainingAppealMonths().' month(s) are left', $answer);

        $appeal = $this->submitted(3);
        $status = $this->ask($this->student, 'where is my appeal', 'cand_my_appeal')['answer'];
        $this->assertStringContainsString($appeal->displayNo(), $status);
        $this->assertStringContainsString(\App\Modules\Chloe\Support\CandidacyAppealPresenter::statusLabel($appeal->fresh()), $status);

        $this->assertStringContainsString('You already have an appeal in progress', $this->ask($this->student, 'can i still appeal', 'cand_can_appeal')['answer']);
    }

    public function test_the_approver_numbers_are_the_portal_numbers_and_only_cover_their_own_appeals(): void
    {
        $this->submitted(3);
        $n = CandidacyPortalQuery::pending($this->lecturer)->count();

        $this->assertSame(1, $n);
        $this->assertStringContainsString('1 appeal is waiting', $this->ask($this->lecturer, 'how many appeals are waiting for me', 'cand_ap_pending')['answer']);
        // the Chair has nothing at their step yet: the same question gives THEIR number
        $this->assertSame(0, CandidacyPortalQuery::pending($this->chairUser)->count());
        $this->assertStringContainsString('No appeal is waiting', $this->ask($this->chairUser, 'how many appeals are waiting for me', 'cand_ap_pending')['answer']);
    }

    public function test_the_cgs_numbers_equal_operations_insights_and_the_dismissal_list(): void
    {
        $appeal = $this->submitted(3);
        $appeal->forceFill(['status' => 'with_cgs', 'current_stage' => 'cgs', 'stage_entered_at' => now()->subDays(12)])->save();
        \App\Modules\Chloe\Models\DemoStudentProfile::create(['matric_no' => '21070001', 'name' => 'Demo Expired', 'email' => 'e@x.my', 'gender' => 'male', 'programme' => 'PhD in Information Systems (Full-Time)', 'programme_start_date' => '2020-01-01', 'candidacy_expiry_date' => '2026-08-01', 'status' => 'active']);
        $this->candidacy->update(['candidacy_expiry_date' => '2026-12-15', 'original_expiry_date' => '2026-12-15']);

        $steps = $this->ask($this->cgsUser, 'appeals at each step', 'cand_cgs_pending_by_step')['answer'];
        $this->assertStringContainsString('CGS verification: '.CandidacyOperationsQuery::base(CandidacyOperationsQuery::filters(['status' => 'with_cgs']))->count(), $steps);
        $this->assertStringContainsString('Total in progress: '.CandidacyOperationsQuery::counts(CandidacyOperationsQuery::filters([]))['in_progress'], $steps);

        $expiring = $this->ask($this->cgsUser, 'how many expire within 3 months', 'cand_cgs_expiring')['answer'];
        $this->assertStringContainsString('within 3 months: '.CandidacyStatistics::summary(CandidacyStatistics::population())['within3'], $expiring);

        $list = CandidacyDismissalList::counts(CandidacyDismissalList::candidates(), CandidacyDismissalList::filters([]));
        $this->assertStringContainsString("Dismissal candidates today: {$list['candidates']}.", $this->ask($this->cgsUser, 'how many dismissal candidates', 'cand_cgs_dismissal_candidates')['answer']);
        $this->assertSame(1, $list['candidates']);

        $this->assertStringContainsString('waited 10 days or more', $this->ask($this->cgsUser, 'appeals waiting over 10 days', 'cand_cgs_waiting_10')['answer']);
    }

    public function test_actions_and_outcome_predictions_are_refused_with_a_reason_and_a_link(): void
    {
        $decide = $this->ask($this->lecturer, 'approve this appeal now', 'cand_action_decide');
        $this->assertSame('refused', $decide['outcome']);
        $this->assertStringContainsString("can't approve, return or reject", $decide['answer']);
        $this->assertSame(route('candidacy.portal.queue'), $decide['links'][0]['url']);

        $submit = $this->ask($this->student, 'submit my appeal for me', 'cand_action_submit');
        $this->assertSame('refused', $submit['outcome']);
        $this->assertSame(route('candidacy.appeals.new'), $submit['links'][0]['url']);

        foreach (['cand_action_reminders', 'cand_action_export_upload', 'cand_action_dismiss'] as $id) {
            $r = $this->ask($this->cgsUser, 'do it now', $id);
            $this->assertSame('refused', $r['outcome'], $id);
            $this->assertNotEmpty($r['links'], $id);
        }

        $prediction = $this->ask($this->student, 'will my appeal be approved', 'cand_outcome_prediction');
        $this->assertStringContainsString('The approvers and the Dean decide', $prediction['answer']);
        $this->assertStringContainsString('Source:', $prediction['answer']);
    }

    public function test_the_fallback_keyword_layer_and_the_imperative_guard(): void
    {
        FakeModel::forceUnavailable();
        $pipeline = app(Pipeline::class);

        $this->assertStringContainsString('expires on 1 Mar 2027', $pipeline->ask($this->student, 'when does my candidacy expire', [])['answer']);
        $this->assertSame('refused', $pipeline->ask($this->student, 'submit my appeal for me', [])['outcome']);
        $this->assertSame('refused', $pipeline->ask($this->lecturer, 'approve the appeal', [])['outcome']);
        // a question (not an imperative) about approval is a how-to, answered from the topic, never an action
        $this->assertSame('answered', $pipeline->ask($this->lecturer, 'what can i do at my step as an approver', [])['outcome']);
        // a Workstation word does not turn into a candidacy answer, and a student never gets a CGS answer from the keyword layer
        $this->assertStringNotContainsString('Dismissal candidates today', $pipeline->ask($this->student, 'how many dismissal candidates are there', [])['answer']);
    }

    public function test_every_topic_has_a_source_line_and_the_answer_shows_it(): void
    {
        foreach (array_keys(CandidacyAssistant::TOPICS) as $topic) {
            $text = CandidacyAssistant::sourceText($topic);
            $this->assertNotNull($text, $topic);
            $this->assertMatchesRegularExpression('/^Source: .+/m', $text, $topic);
            $this->assertStringNotContainsString('{{', $text, "unreplaced placeholder in {$topic}");
        }

        $roleOf = fn (string $topic) => in_array('student', CandidacyAssistant::TOPICS[$topic], true) ? $this->student : $this->cgsUser;
        $answer = $this->ask($roleOf('cand_required_documents'), 'what documents do i need', 'cand_required_documents');
        $this->assertStringContainsString('Source: CGS process', $answer['answer']);
        $this->assertStringContainsString('10 MB', $answer['answer']);
    }

    public function test_privacy_the_model_sees_no_name_id_email_appeal_number_or_date_of_a_person_and_the_log_has_no_text(): void
    {
        $appeal = $this->submitted(3);
        $this->ask($this->cgsUser, "what about appeal {$appeal->displayNo()} for 22001001", 'out_of_scope');

        $call = FakeModel::routeCalls()[0];
        $this->assertStringNotContainsString('22001001', $call['maskedQuestion']);

        // an e-mail address is asked back before the model is called at all
        $calls = FakeModel::routeCallCount();
        $this->ask($this->cgsUser, 'what about nobody@utp.edu.my', 'out_of_scope');
        $this->assertSame($calls, FakeModel::routeCallCount());

        $everything = json_encode($call);
        foreach ([$this->student->name, $this->lecturer->name, $this->student->email, $this->candidacy->candidacy_expiry_date->format('Y-m-d')] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $everything);
        }

        $name = $this->ask($this->cgsUser, 'how is '.explode(' ', $this->student->name)[0].' '.($this->student->name), 'cand_my_expiry');
        $this->assertSame('refused', $name['outcome'], 'a known student name in a question is refused');

        $this->ask($this->student, 'when does my candidacy expire', 'cand_my_expiry');
        foreach (AssistantLog::all() as $row) {
            foreach (['candidacy expire', 'Your study candidacy', (string) $this->student->name, 'Mar 2027'] as $text) {
                $this->assertStringNotContainsString($text, json_encode($row->getAttributes()));
            }
        }
    }

    public function test_suggested_questions_are_six_per_role_and_answered_without_a_model_call(): void
    {
        foreach (['student', 'approver', 'cgs'] as $role) {
            $this->assertCount(6, CandidacyAssistant::suggestions($role));
        }

        $before = FakeModel::routeCallCount();
        $result = $this->ask($this->student, 'When does my candidacy expire?');

        $this->assertSame($before, FakeModel::routeCallCount());
        $this->assertStringContainsString('1 Mar 2027', $result['answer']);

        $suggestions = $this->actingAs($this->student)->getJson(route('workstation.assistant.suggestions', ['domain' => 'candidacy']))->assertOk()->json('suggestions');
        $this->assertCount(6, $suggestions);
        $this->assertSame('cand_my_expiry', $suggestions[0]['id']);

        $this->actingAs($this->student)->postJson(route('workstation.assistant.suggestion'), ['id' => 'cand_my_expiry'])->assertOk()->assertJsonPath('outcome', 'answered');
        $this->actingAs($this->student)->postJson(route('workstation.assistant.suggestion'), ['id' => 'cand_cgs_expiring'])->assertOk()->assertJsonPath('outcome', 'error');
    }

    public function test_the_interpreted_as_line_reads_naturally_with_and_without_parameters(): void
    {
        $this->assertSame('How many students expire within 2 months?', $this->ask($this->cgsUser, 'students expiring within 2 months', 'cand_cgs_expiring', ['months' => 2])['interpreted_as']);
        $this->assertSame('How many students expire within 3 months?', $this->ask($this->cgsUser, 'how many are expiring', 'cand_cgs_expiring')['interpreted_as']);
        $this->assertSame('How many students were dismissed last month?', $this->ask($this->cgsUser, 'dismissed last month', 'cand_cgs_dismissed', ['window' => 'last_month'])['interpreted_as']);
        $this->assertSame('How many students were dismissed this month?', $this->ask($this->cgsUser, 'dismissed lately', 'cand_cgs_dismissed')['interpreted_as']);
    }
}
