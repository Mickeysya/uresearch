<?php

namespace Tests\Feature;

use App\Modules\Chloe\Assistant\FakeModel;
use App\Modules\Chloe\Assistant\Pipeline;
use App\Modules\Chloe\Assistant\SuggestedQuestions;
use App\Modules\Chloe\Models\LockerKey;
use App\Modules\Chloe\Models\StudentGender;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Support\WorkstationStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesEligibleWorkstationStudents;
use Tests\Support\MakesFakeAssistant;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * PART C/G.1: every one of the twelve suggested questions answers
 * correctly with seeded data, and never touches the model (FakeModel::
 * answerCalls()/aggregateCalls() stay empty) -- answering a suggestion is
 * never allowed to spend quota.
 */
class WorkstationAssistantSuggestedAnswersTest extends TestCase
{
    use MakesEligibleWorkstationStudents;
    use MakesFakeAssistant;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakeAssistant();
    }

    protected function room(string $gender = 'male', string $code = 'N2-02-01'): WorkstationLocation
    {
        return WorkstationLocation::firstOrCreate(
            ['room_code' => $code],
            ['block' => 'N2', 'gender' => $gender, 'name' => $code]
        );
    }

    protected function seat(WorkstationLocation $room, string $code, string $status = Workstation::STATUS_AVAILABLE): Workstation
    {
        return Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => $code, 'position' => 1, 'status' => $status]);
    }

    protected function assertSuggestionAnswered(string $id, $user, array $mustContain = []): array
    {
        $role = Pipeline::normalizeRole($user->role);
        $result = app(Pipeline::class)->askSuggestion($user, $id);

        $this->assertSame('answered', $result['outcome'], "Suggestion {$id} did not answer.");
        $this->assertEmpty(FakeModel::answerCalls(), "Suggestion {$id} must never call the model.");
        $this->assertEmpty(FakeModel::aggregateCalls(), "Suggestion {$id} must never call the model.");

        foreach ($mustContain as $needle) {
            $this->assertStringContainsString($needle, $result['answer'], "Suggestion {$id}'s answer is missing \"{$needle}\".");
        }

        return $result;
    }

    // -------------------------------------------------------------
    // Student
    // -------------------------------------------------------------

    public function test_s1_rules(): void
    {
        $student = $this->eligibleStudent();

        $this->assertSuggestionAnswered('s1_rules', $student, ['7:00 AM', 'working days']);
    }

    public function test_s2_loan_end_with_no_seat(): void
    {
        $student = $this->eligibleStudent();

        $this->assertSuggestionAnswered('s2_loan_end', $student, ['do not currently hold a workstation']);
    }

    public function test_s2_loan_end_with_an_active_notice(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $seat = $this->seat($this->room(), '1', Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);
        WorkstationTerminationNotice::create(['workstation_request_id' => $request->id, 'reason' => 'Withdrawn', 'notice_date' => now(), 'vacate_by_date' => now()->addDays(5), 'status' => WorkstationTerminationNotice::STATUS_ACTIVE]);

        $this->assertSuggestionAnswered('s2_loan_end', $student, ['terminated', 'vacate by']);
    }

    public function test_s2_loan_end_with_a_normal_active_loan(): void
    {
        $student = $this->eligibleStudent();
        StudentGender::create(['student_id' => $student->id, 'gender' => 'male']);
        $seat = $this->seat($this->room(), '1', Workstation::STATUS_OCCUPIED);
        WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);

        $this->assertSuggestionAnswered('s2_loan_end', $student, ['Your loan is valid until']);
    }

    public function test_s3_locker_key_not_requested(): void
    {
        $student = $this->eligibleStudent();

        $this->assertSuggestionAnswered('s3_locker_key', $student, ['have not requested a locker key']);
    }

    public function test_s3_locker_key_pending_collection(): void
    {
        $student = $this->eligibleStudent();
        $seat = $this->seat($this->room(), '1', Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);
        LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_REQUESTED, 'requested_at' => now()]);

        $this->assertSuggestionAnswered('s3_locker_key', $student, ['Collect it at CGS by']);
    }

    public function test_s3_locker_key_return_pending_overdue(): void
    {
        $student = $this->eligibleStudent();
        $seat = $this->seat($this->room(), '1', Workstation::STATUS_AWAITING_KEY_RETURN);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $student->id, 'status' => WorkstationRequest::STATUS_RELEASED, 'requested_at' => now(), 'released_at' => now()]);
        LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $student->id, 'status' => LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subDays(10), 'collected_at' => now()->subDays(10), 'return_due_at' => now()->subDay()]);

        $this->assertSuggestionAnswered('s3_locker_key', $student, ['This is overdue.']);
    }

    public function test_s4_change_workstation(): void
    {
        $student = $this->eligibleStudent();

        $this->assertSuggestionAnswered('s4_change_workstation', $student, ['Return Workstation']);
    }

    public function test_s5_lost_key(): void
    {
        $student = $this->eligibleStudent();

        $this->assertSuggestionAnswered('s5_lost_key', $student, ['clause 6']);
    }

    public function test_s6_get_key(): void
    {
        $student = $this->eligibleStudent();

        $this->assertSuggestionAnswered('s6_get_key', $student, ['Centre for Graduate Studies']);
    }

    // -------------------------------------------------------------
    // CGS
    // -------------------------------------------------------------

    public function test_c1_release_outlook(): void
    {
        $cgs = $this->cgs();

        $this->assertSuggestionAnswered('c1_release_outlook', $cgs, ['Termination notice', 'Awaiting Key Return', 'Candidacy expiring']);
    }

    public function test_c2_almost_full_with_no_room_above_threshold(): void
    {
        $cgs = $this->cgs();
        $this->seat($this->room(), '1', Workstation::STATUS_AVAILABLE);

        $this->assertSuggestionAnswered('c2_almost_full', $cgs, ['No room is at']);
    }

    public function test_c2_almost_full_with_a_room_above_threshold(): void
    {
        $cgs = $this->cgs();
        $room = $this->room('male', 'N2-09-09');
        $this->seat($room, '1', Workstation::STATUS_OCCUPIED);

        $this->assertSuggestionAnswered('c2_almost_full', $cgs, ['N2-09-09']);
    }

    public function test_c3_termination_detailed(): void
    {
        $cgs = $this->cgs();
        $seat = $this->seat($this->room(), '1', Workstation::STATUS_OCCUPIED);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $this->eligibleStudent()->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()]);
        WorkstationTerminationNotice::create(['workstation_request_id' => $request->id, 'reason' => 'Graduated', 'notice_date' => now(), 'vacate_by_date' => now()->addDays(5), 'status' => WorkstationTerminationNotice::STATUS_ACTIVE]);

        $this->assertSuggestionAnswered('c3_termination_detailed', $cgs, ['1 seats', 'Graduated: 1']);
    }

    public function test_c4_candidacy_expiry(): void
    {
        $cgs = $this->cgs();

        $this->assertSuggestionAnswered('c4_candidacy_expiry', $cgs, ['Loan period ended (candidacy expiry)']);
    }

    public function test_c5_reassign_release(): void
    {
        $cgs = $this->cgs();

        $this->assertSuggestionAnswered('c5_reassign_release', $cgs, ['Reassign:', 'Release:']);
    }

    public function test_c6_key_lost_cgs_side(): void
    {
        $cgs = $this->cgs();

        $this->assertSuggestionAnswered('c6_key_lost_cgs_side', $cgs, ['Release Hold']);
    }

    // -------------------------------------------------------------
    // Every id in SuggestedQuestions actually resolves (catches a typo
    // between the id used there and the one SuggestedAnswers switches on).
    // -------------------------------------------------------------

    public function test_every_suggested_question_id_resolves_to_a_real_answer(): void
    {
        $student = $this->eligibleStudent();
        $cgs = $this->cgs();

        foreach (SuggestedQuestions::forRole('student') as $id => $s) {
            $this->assertSuggestionAnswered($id, $student);
        }
        foreach (SuggestedQuestions::forRole('cgs') as $id => $s) {
            $this->assertSuggestionAnswered($id, $cgs);
        }
    }

    // -------------------------------------------------------------
    // PART F.4: the Insights page and the chat's new lookups must never
    // disagree -- both call the exact same WorkstationStatistics methods.
    // -------------------------------------------------------------

    public function test_insights_numbers_match_the_chat_lookups(): void
    {
        $cgs = $this->cgs();
        $room = $this->room('male', 'N2-09-09');
        $this->seat($room, '1', Workstation::STATUS_OCCUPIED);

        $chatAlmostFull = \App\Modules\Chloe\Assistant\Lookups::run('almost_full_rooms', [], $cgs, 'cgs');
        $insightsAlmostFull = WorkstationStatistics::almostFullRooms((float) config('assistant.almost_full_percent', 90));

        $this->assertStringContainsString('N2-09-09', $chatAlmostFull['text']);
        $this->assertNotEmpty($insightsAlmostFull);
        $this->assertSame('N2-09-09', $insightsAlmostFull[0]['room_code']);

        $chatInsightsSummary = \App\Modules\Chloe\Assistant\Lookups::run('insights_summary', [], $cgs, 'cgs');
        $pageHighlights = WorkstationStatistics::keyHighlights();
        $this->assertSame(implode("\n", $pageHighlights), $chatInsightsSummary['text']);
    }

    public function test_the_insights_page_is_403_for_a_student(): void
    {
        $student = $this->eligibleStudent();

        $this->actingAs($student)->get(route('workstation.cgs.insights'))->assertForbidden();
    }

    public function test_the_insights_page_is_ok_for_cgs_and_shows_the_widget(): void
    {
        $cgs = $this->cgs();

        $this->actingAs($cgs)->get(route('workstation.cgs.insights'))
            ->assertOk()
            ->assertSee('id="wsa-root"', false)
            // Round 5 redesign: highlights are now the KPI row, built
            // entirely client-side from the embedded fact rows -- no
            // "Key highlights" text is ever server-rendered any more.
            // See WorkstationInsightsTest for the new design's own
            // equivalent guarantees (fact rows, KPI thresholds, etc.).
            ->assertSee('id="wsi-kpi-row"', false);
    }

    // -------------------------------------------------------------
    // Round 3 PART F: the Insights redesign's own regression tests --
    // the two root-cause bugs found this round (no bar fill, Home
    // button cut off) stay fixed.
    // -------------------------------------------------------------

    /**
     * Round 4 PART C redesign: the page is now entirely client-side from
     * one embedded JSON block (no server-rendered SVG/rect markup at all
     * any more -- see tests/Feature/WorkstationInsightsTest.php for the
     * new design's own equivalent guarantees, including that the
     * embedded JSON carries real numbers and that no <canvas>/inline
     * style exists anywhere on the page). Kept here only as a quick
     * smoke check that the page still loads with the chart script.
     */
    public function test_the_insights_page_loads_the_chart_script(): void
    {
        $cgs = $this->cgs();

        $this->actingAs($cgs)->get(route('workstation.cgs.insights'))
            ->assertOk()
            ->assertSee('chloe-workstation-insights.js', false);
    }

    public function test_the_insights_page_has_a_home_button_and_a_back_link(): void
    {
        $cgs = $this->cgs();

        $this->actingAs($cgs)->get(route('workstation.cgs.insights'))
            ->assertOk()
            ->assertSee('Home')
            ->assertSee('&larr; Back', false);
    }

    /** The embedded fact rows for this room must match WorkstationStatistics::roomOccupancy() directly -- see WorkstationInsightsTest for the full fact-row cross-check. */
    public function test_the_room_occupancy_card_numbers_match_workstation_statistics(): void
    {
        $cgs = $this->cgs();
        $room = $this->room('male', 'N2-08-08');
        $this->seat($room, '1', Workstation::STATUS_OCCUPIED);
        $this->seat($room, '2', Workstation::STATUS_AVAILABLE);

        $rows = $this->actingAs($cgs)->get(route('workstation.cgs.insights'))->viewData('rows');

        $stats = collect(WorkstationStatistics::roomOccupancy())->firstWhere('room_code', 'N2-08-08');
        $embeddedOccupied = collect($rows)->where('room', 'N2-08-08')->where('occupied', true)->count();
        $embeddedTotal = collect($rows)->where('room', 'N2-08-08')->count();
        $this->assertNotNull($stats);
        $this->assertSame($stats['occupied'], $embeddedOccupied);
        $this->assertSame($stats['total'], $embeddedTotal);
    }

    public function test_the_seat_release_forecast_breaks_down_by_source(): void
    {
        $forecast = WorkstationStatistics::seatReleaseForecast(6);

        $this->assertArrayHasKey('from_candidacy', $forecast[0]);
        $this->assertArrayHasKey('from_termination', $forecast[0]);
        $this->assertSame($forecast[0]['from_candidacy'] + $forecast[0]['from_termination'], $forecast[0]['releases']);
    }

    public function test_key_highlights_structured_rows_are_ordered_by_urgency_with_a_fallback(): void
    {
        $cgs = $this->cgs();

        // No seats/keys/notices seeded at all -- every category is empty.
        $rows = WorkstationStatistics::keyHighlightsStructured();
        $this->assertSame('Nothing needs attention right now.', $rows[0]['text']);

        $this->seat($this->room(), '1', Workstation::STATUS_AWAITING_KEY_RETURN);
        $request = \App\Modules\Chloe\Models\WorkstationRequest::create(['workstation_id' => 1, 'student_id' => $this->eligibleStudent()->id, 'status' => \App\Modules\Chloe\Models\WorkstationRequest::STATUS_RELEASED, 'requested_at' => now(), 'released_at' => now()]);
        \App\Modules\Chloe\Models\LockerKey::create(['workstation_request_id' => $request->id, 'student_id' => $request->student_id, 'status' => \App\Modules\Chloe\Models\LockerKey::STATUS_RETURN_PENDING, 'requested_at' => now()->subDays(10), 'collected_at' => now()->subDays(10), 'return_due_at' => now()->subDay()]);

        $rows = WorkstationStatistics::keyHighlightsStructured();
        $this->assertSame('red', $rows[0]['color'], 'An overdue key return must be the most urgent row.');
    }
}
