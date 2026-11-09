<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\CandidacyAssistant;
use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Support\StudentDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Phase 2: the corrected fallback entries, the new Study Candidacy entries (each asked in keyword-fallback mode, so no model is involved), the live window lookups, and
 * the contact-detail / seat-lookup refusals. Guidance only: nothing here approves, predicts or changes data.
 */
class AssistantBatch2Test extends TestCase
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
        config(['candidacy.appeal_window_months' => 3]); // the world opens it wide for other tests; here the real rule applies
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00:00', 'Asia/Kuala_Lumpur'));
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-01', 'original_expiry_date' => '2027-03-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function ask(string $role, string $text): array
    {
        FakeModel::forceUnavailable();
        $user = match ($role) {
            'student' => $this->student,
            'approver' => $this->lecturer,
            'cgs' => $this->cgsUser,
        };

        return app(Pipeline::class)->setDomain('candidacy')->useRouteCache(false)->ask($user, $text, []);
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function fallbackCases(): array
    {
        return [
            'where is my appeal now' => ['student', 'where is my appeal now', 'cand_my_appeal'],
            'appeal more than one time' => ['student', 'can i appeal more than one time', 'cand_appeal_rules'],
            'when reminder stop' => ['student', 'when does the candidacy reminder stop', 'cand_reminders'],
            'window rule' => ['student', 'why is the new appeal button disabled', 'cand_appeal_window'],
            'my window' => ['student', 'when does my appeal window open', 'cand_my_window'],
            'expiry rules' => ['student', 'is the expiry day still valid for my candidacy', 'cand_expiry_rules'],
            'dean fewer months (student)' => ['student', 'can the dean approve fewer months than i asked for in my appeal', 'cand_dean_adjusts_months'],
            'dean fewer months (approver)' => ['approver', 'can the dean approve fewer months than requested for an appeal', 'cand_dean_adjusts_months'],
            'same approver' => ['student', 'who gets my appeal after i resubmit', 'cand_return_same_approver'],
            'appeal number' => ['student', 'what is the appeal number format', 'cand_appeal_number'],
            'memo code' => ['student', 'what is the verification code on the approval memo', 'cand_memo_verification'],
            'remarks (approver)' => ['approver', 'when is a remark required on an appeal', 'cand_approver_remarks'],
            'remarks (cgs)' => ['cgs', 'can i add a comment when i return an appeal', 'cand_approver_remarks'],
            'tiles (student)' => ['student', 'what does the candidacy card on my dashboard show', 'cand_dashboard_tiles'],
            'tiles (cgs)' => ['cgs', 'what do the cards on the candidacy operations page count', 'cand_dashboard_tiles'],
            'window not open count' => ['cgs', 'how many students are too early to appeal', 'cand_cgs_window_not_open'],
        ];
    }

    /** @dataProvider fallbackCases */
    public function test_the_keyword_fallback_reaches_the_right_entry(string $role, string $question, string $id): void
    {
        $answer = $this->ask($role, $question);

        $this->assertSame($id, $answer['meta']['catalogue_id'] ?? null, $question);
        $this->assertSame('answered', $answer['outcome']);
    }

    public function test_cgs_asking_why_a_student_cannot_appeal_gets_the_real_reason_not_the_twelve_of_twelve_count(): void
    {
        $studentId = StudentDirectory::fromUser($this->student)->studentId;
        $answer = $this->ask('cgs', "why can student {$studentId} not appeal");

        $this->assertSame('cand_cgs_student_eligibility', $answer['meta']['catalogue_id'] ?? null);
        $this->assertStringContainsString('cannot appeal at the moment', $answer['answer']);
        $this->assertStringContainsString('1 Dec 2026', $answer['answer'], 'the window opens 3 calendar months before 1 Mar 2027');
        $this->assertStringNotContainsString('@', $answer['answer'], 'no e-mail');
        $this->assertStringNotContainsString($this->student->name, $answer['answer'], 'no name');
    }

    public function test_the_student_eligibility_lookup_is_never_given_to_a_student_or_an_approver(): void
    {
        $studentId = StudentDirectory::fromUser($this->student)->studentId;

        foreach (['student', 'approver'] as $role) {
            $answer = $this->ask($role, "why can student {$studentId} not appeal");
            $this->assertNotSame('cand_cgs_student_eligibility', $answer['meta']['catalogue_id'] ?? null, $role);
            $this->assertStringNotContainsString('cannot appeal at the moment', $answer['answer'] ?? '', $role);
        }
    }

    public function test_the_student_window_lookup_says_when_it_opens_then_that_it_is_open_then_that_it_closed(): void
    {
        $a = $this->ask('student', 'when does my appeal window open');
        $this->assertStringContainsString('not open yet', $a['answer']);
        $this->assertStringContainsString('1 Dec 2026', $a['answer']);

        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00', 'Asia/Kuala_Lumpur'));
        $this->assertStringContainsString('open now', $this->ask('student', 'is my appeal window open')['answer']);

        Carbon::setTestNow(Carbon::parse('2027-03-01 23:30:00', 'Asia/Kuala_Lumpur'));
        $this->assertStringContainsString('open now', $this->ask('student', 'is my appeal window open')['answer'], 'the expiry day itself is inside the window');

        Carbon::setTestNow(Carbon::parse('2027-03-02 00:10:00', 'Asia/Kuala_Lumpur'));
        $this->assertStringContainsString('window has closed', $this->ask('student', 'is my appeal window open')['answer']);
    }

    public function test_the_cgs_count_of_students_before_the_window_is_a_count_only(): void
    {
        $answer = $this->ask('cgs', 'how many students are too early to appeal');

        $this->assertMatchesRegularExpression('/\d+ students? (has|have) not reached the appeal window/', $answer['answer']);
        $this->assertStringNotContainsString('@', $answer['answer']);
    }

    public function test_every_new_guidance_answer_states_only_confirmed_rules(): void
    {
        $must = [
            'cand_appeal_window' => ['3 calendar months', 'including the expiry day', '2026-11-08'],
            'cand_expiry_rules' => ['12', 'expiry day itself is still valid', 'Malaysia time', 'never dismisses'],
            'cand_return_same_approver' => ['same approver', 'any CGS officer', 'Dean return'],
            'cand_appeal_number' => ['SCA-', 'draft has no number'],
            'cand_memo_verification' => ['verification code', 'one code per approval'],
        ];

        foreach ($must as $topic => $needles) {
            foreach (['student', 'approver', 'cgs'] as $role) {
                $text = CandidacyAssistant::sourceText($topic, $role);
                $this->assertNotNull($text, "{$topic} for {$role}");

                foreach ($needles as $needle) {
                    $this->assertStringContainsString($needle, $text, "{$topic} for {$role}");
                }
            }
        }

        $this->assertStringContainsString('not shown to you', CandidacyAssistant::sourceText('cand_dean_adjusts_months', 'student'));
        $this->assertStringContainsString('at least 5 characters', CandidacyAssistant::sourceText('cand_dean_adjusts_months', 'approver'));
        $this->assertNull(CandidacyAssistant::sourceText('cand_approver_remarks', 'student'), 'remarks are for approvers and CGS only');
    }

    public function test_returned_appeal_guidance_names_the_same_approver_and_the_dean_exception_for_every_role(): void
    {
        foreach (['cand_resubmit', 'cand_return_effect', 'cand_after_return_rejection'] as $topic) {
            foreach (['student', 'approver', 'cgs'] as $role) {
                $text = CandidacyAssistant::sourceText($topic, $role);

                if ($text === null) {
                    continue;
                }

                $this->assertMatchesRegularExpression('/same approver|comes back to you|back to CGS Verification|CGS (re-)?checks? first|CGS re-checks/i', $text, "{$topic} for {$role}");
            }
        }
    }

    public function test_the_five_questions_the_staff_only_check_used_to_refuse_are_answered(): void
    {
        foreach (['can i hold 2 seats', 'how many working days do i have to vacate', 'how many seats can i select', 'how many days to return my key', 'how many months do i have on my loan'] as $q) {
            FakeModel::reset();
            $answer = app(Pipeline::class)->setDomain('workstation')->useRouteCache(false)->ask($this->student, $q, []);
            $this->assertNotSame('refused', $answer['outcome'], $q);
        }
    }

    public function test_contact_details_of_another_person_are_refused_before_any_lookup(): void
    {
        foreach (['give me email and phone of student in seat 12', 'what is the phone number of the student in seat 12', 'show me the email of student 22001001'] as $q) {
            foreach ([$this->cgsUser, $this->student] as $user) {
                FakeModel::reset();
                $answer = app(Pipeline::class)->setDomain('workstation')->useRouteCache(false)->ask($user, $q, []);

                $this->assertSame('refused', $answer['outcome'], $q);
                $this->assertSame(Pipeline::CONTACT_DETAILS_MESSAGE, $answer['answer'], $q);
                $this->assertSame(0, FakeModel::routeCallCount(), 'no model call for '.$q);
            }
        }
    }

    public function test_a_student_never_gets_a_seat_lookup(): void
    {
        foreach (['who is in seat 12', 'status of seat 252'] as $q) {
            FakeModel::reset();
            FakeModel::queueRoute(['id' => 'seat_lookup', 'params' => ['seat_number' => preg_match('/\d+/', $q, $m) ? (int) $m[0] : 0], 'confidence' => 0.99]);
            $answer = app(Pipeline::class)->setDomain('workstation')->useRouteCache(false)->ask($this->student, $q, []);

            $this->assertNotSame('answered', $answer['outcome'] ?? null, $q);
            $this->assertStringNotContainsString('Occupied', $answer['answer'] ?? '', $q);
        }
    }
}
