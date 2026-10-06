<?php

namespace Tests\Feature\Chloe;

use App\Modules\Chloe\Models\CandidacyAppealDetail;
use App\Modules\Chloe\Models\CandidacyDismissal;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyDismissed;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Dismiss Exceeded Study Candidacy: the generated list (DismissalGenerator,
 * shared by the daily command and CGS's Refresh button) and CGS's confirm.
 * Registry's own step is outside the system; only the list and the
 * confirmation are tested here.
 */
class CandidacyDismissalTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function candidacy(User $student, array $overrides = []): StudyCandidacy
    {
        return StudyCandidacy::create($overrides + [
            'student_id' => $student->id,
            'programme_start_date' => now()->subYears(4),
            'candidacy_expiry_date' => now()->subDay(),
            'status' => StudyCandidacy::STATUS_ACTIVE,
        ]);
    }

    protected function reasonFor(StudyCandidacy $candidacy): ?string
    {
        return CandidacyDismissal::where('study_candidacy_id', $candidacy->id)->value('reason');
    }

    public function test_the_list_gives_each_candidacy_its_most_specific_reason(): void
    {
        $expired = $this->candidacy($this->student('22001001', 'a@test.my'));
        $exhausted = $this->candidacy($this->student('22001002', 'b@test.my'), ['cumulative_extension_months' => 12]);
        // Rejected AND past expiry: the rejection is the more specific reason.
        $rejected = $this->candidacy($this->student('22001003', 'c@test.my'), ['last_rejection_at' => now()->subWeek()]);

        $this->artisan('candidacy:generate-dismissals')->assertSuccessful();

        $this->assertSame(CandidacyDismissal::REASON_EXPIRED_NO_APPEAL, $this->reasonFor($expired));
        $this->assertSame(CandidacyDismissal::REASON_EXTENSION_EXHAUSTED, $this->reasonFor($exhausted));
        $this->assertSame(CandidacyDismissal::REASON_APPEAL_REJECTED, $this->reasonFor($rejected));
        $this->assertSame(3, CandidacyDismissal::where('status', CandidacyDismissal::STATUS_PENDING_REVIEW)->count());
    }

    public function test_in_date_and_inactive_candidacies_are_not_listed(): void
    {
        $this->candidacy($this->student('22001001', 'a@test.my'), ['candidacy_expiry_date' => now()->addMonth()]);
        $this->candidacy($this->student('22001002', 'b@test.my'), ['status' => StudyCandidacy::STATUS_SOFTBOUND]);

        $this->artisan('candidacy:generate-dismissals')->assertSuccessful();

        $this->assertSame(0, CandidacyDismissal::count());
    }

    public function test_a_student_with_an_appeal_in_flight_is_not_listed(): void
    {
        $student = $this->student();
        $supervisor = $this->supervisor();
        $candidacy = $this->candidacy($student);

        // Filed through the real route, so the appeal is pending exactly as
        // it would be in use.
        $this->actingAs($student)->post(route('candidacy-appeal.store'), [
            'supervisor_id' => $supervisor->id,
            'phase' => CandidacyAppealDetail::PHASE_WRITING,
            'writing_completion_percent' => 80,
            'rcs_status' => CandidacyAppealDetail::RCS_PENDING,
            'rcs_expected_date' => now()->addMonth()->format('Y-m-d'),
            'extension_via_gsc' => 'no',
            'extension_via_vc' => 'no',
            'requested_extension_months' => 3,
            'disclaimer' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($candidacy->hasOpenAppeal());

        $this->artisan('candidacy:generate-dismissals')->assertSuccessful();

        $this->assertNull($this->reasonFor($candidacy));
    }

    public function test_running_the_list_twice_does_not_list_anyone_twice(): void
    {
        $this->candidacy($this->student());

        $this->artisan('candidacy:generate-dismissals')->assertSuccessful();
        $this->artisan('candidacy:generate-dismissals')->assertSuccessful();

        $this->assertSame(1, CandidacyDismissal::count());
    }

    public function test_cgs_refresh_and_confirm_dismiss_the_candidacy_and_notify_once(): void
    {
        $cgs = $this->cgs();
        $student = $this->student();
        $candidacy = $this->candidacy($student);

        $this->actingAs($cgs)
            ->post(route('candidacy.cgs.dismissals.refresh'))
            ->assertSessionHas('status', '1 new candidate(s) added to the list.');

        $dismissal = CandidacyDismissal::sole();

        $this->actingAs($cgs)
            ->get(route('candidacy.cgs.dismissals'))
            ->assertOk()
            ->assertSee($student->name);

        $this->actingAs($cgs)
            ->post(route('candidacy.cgs.dismissals.confirm', $dismissal))
            ->assertSessionHas('status');

        $dismissal->refresh();
        $candidacy->refresh();
        $this->assertSame(CandidacyDismissal::STATUS_CONFIRMED, $dismissal->status);
        $this->assertSame($cgs->id, $dismissal->confirmed_by_id);
        $this->assertSame(StudyCandidacy::STATUS_DISMISSED, $candidacy->status);
        $this->assertSame($cgs->id, $candidacy->dismissed_by_id);

        // A second confirm is refused and does not email the student again.
        $this->actingAs($cgs)
            ->post(route('candidacy.cgs.dismissals.confirm', $dismissal))
            ->assertSessionHas('error');

        Notification::assertSentToTimes($student, CandidacyDismissed::class, 1);
    }

    public function test_a_student_cannot_reach_the_dismissal_list(): void
    {
        $student = $this->student();
        $this->candidacy($student);
        $this->artisan('candidacy:generate-dismissals');
        $dismissal = CandidacyDismissal::sole();

        $this->actingAs($student)->get(route('candidacy.cgs.dismissals'))->assertForbidden();
        $this->actingAs($student)->post(route('candidacy.cgs.dismissals.confirm', $dismissal))->assertForbidden();

        $this->assertSame(CandidacyDismissal::STATUS_PENDING_REVIEW, $dismissal->fresh()->status);
    }
}
