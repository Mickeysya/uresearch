<?php

namespace Tests\Feature\Haziq;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Notifications\GaInterviewScheduled;
use App\Modules\Haziq\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Confirmed explicitly 2026-11-10, then refined twice the same day:
 * first "date taleh sampai 2 hari set satu date je, timing je set from
 * what time end what time" (one date, two times on it, never two
 * independent timestamps that could land on different days), then "set
 * timing tu cam tak user friendly je, buat lah am pm features terima
 * 1-12 tu" (hour 1-12 + AM/PM, not a raw 24h time input).
 */
class GaScheduleInterviewTest extends TestCase
{
    use RefreshDatabase;

    protected function gaApplicationAtResearchCentreStage(string $department): Application
    {
        $student = User::create(['name' => 'Student', 'email' => 'student'.uniqid().'@test.com', 'password' => 'password', 'role' => Role::STUDENT, 'department' => $department]);
        $application = Application::create(['student_id' => $student->id, 'module_type' => 'ga_application', 'status' => Application::STATUS_PENDING, 'current_stage' => 'research_centre_interview', 'submitted_at' => now()]);

        GaApplicationDetail::create([
            'application_id' => $application->id,
            'profile_id' => GraGaStudentProfile::create(['user_id' => $student->id, 'level_of_study' => 'phd', 'programme' => 'CS', 'admission_date' => now()])->id,
            'proposed_hours_per_week' => 10, 'proposed_allowance' => 2000,
            'service_start_date' => now(), 'service_end_date' => now()->addYears(2),
        ]);

        return $application;
    }

    protected function researchCentreUser(string $department): User
    {
        return User::create(['name' => 'RC', 'email' => 'rc'.uniqid().'@test.com', 'password' => 'password', 'role' => Roles::RESEARCH_CENTRE, 'department' => $department]);
    }

    /** @return array<string, mixed> a full, valid request body, so each test only overrides what it's actually testing. */
    protected function validRequest(array $overrides = []): array
    {
        return array_merge([
            'interview_date' => now()->addDay()->format('Y-m-d'),
            'interview_start_hour' => '2', 'interview_start_minute' => '00', 'interview_start_meridiem' => 'PM',
            'interview_end_hour' => '4', 'interview_end_minute' => '00', 'interview_end_meridiem' => 'PM',
        ], $overrides);
    }

    public function test_scheduling_without_an_end_hour_fails_validation(): void
    {
        $reviewer = $this->researchCentreUser('Computing');
        $application = $this->gaApplicationAtResearchCentreStage('Computing');
        $request = $this->validRequest();
        unset($request['interview_end_hour']);

        $this->actingAs($reviewer)
            ->post(route('ga-application.schedule-interview', $application), $request)
            ->assertSessionHasErrors('interview_end_hour');

        $this->assertNull(GaApplicationDetail::where('application_id', $application->id)->value('interview_date'));
    }

    public function test_an_hour_outside_1_to_12_fails_validation(): void
    {
        $reviewer = $this->researchCentreUser('Computing');
        $application = $this->gaApplicationAtResearchCentreStage('Computing');

        $this->actingAs($reviewer)
            ->post(route('ga-application.schedule-interview', $application), $this->validRequest(['interview_start_hour' => '13']))
            ->assertSessionHasErrors('interview_start_hour');
    }

    public function test_an_end_time_before_the_start_on_the_same_date_is_refused(): void
    {
        $reviewer = $this->researchCentreUser('Computing');
        $application = $this->gaApplicationAtResearchCentreStage('Computing');

        $this->actingAs($reviewer)
            ->post(route('ga-application.schedule-interview', $application), $this->validRequest([
                'interview_start_hour' => '4', 'interview_start_meridiem' => 'PM',
                'interview_end_hour' => '2', 'interview_end_meridiem' => 'PM',
            ]))
            ->assertSessionHasNoErrors() // not a field-validation error -- a business rule, see the controller's own check.
            ->assertSessionHas('error', 'The interview must end after it starts.');

        $this->assertNull(GaApplicationDetail::where('application_id', $application->id)->value('interview_date'));
    }

    public function test_scheduling_with_valid_times_stores_both_on_the_same_date_and_notifies_the_student_with_both(): void
    {
        Notification::fake();
        $reviewer = $this->researchCentreUser('Computing');
        $application = $this->gaApplicationAtResearchCentreStage('Computing');
        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($reviewer)
            ->post(route('ga-application.schedule-interview', $application), $this->validRequest(['interview_date' => $date]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $detail = GaApplicationDetail::where('application_id', $application->id)->first();
        $this->assertSame("{$date} 14:00", $detail->interview_date->format('Y-m-d H:i'));
        $this->assertSame("{$date} 16:00", $detail->interview_ends_at->format('Y-m-d H:i'));

        Notification::assertSentTo(
            $application->student,
            GaInterviewScheduled::class,
            fn ($notification) => str_contains($notification->toArray($application->student)['body'], '2:00pm to 4:00pm')
        );
    }

    /** 12 AM is midnight (00:xx), 12 PM is noon (12:xx) -- the one case where the hour digit alone is ambiguous. */
    public function test_12am_and_12pm_convert_to_the_correct_24_hour_time(): void
    {
        $reviewer = $this->researchCentreUser('Computing');
        $application = $this->gaApplicationAtResearchCentreStage('Computing');
        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($reviewer)
            ->post(route('ga-application.schedule-interview', $application), $this->validRequest([
                'interview_date' => $date,
                'interview_start_hour' => '12', 'interview_start_minute' => '00', 'interview_start_meridiem' => 'AM',
                'interview_end_hour' => '12', 'interview_end_minute' => '00', 'interview_end_meridiem' => 'PM',
            ]))
            ->assertSessionHasNoErrors();

        $detail = GaApplicationDetail::where('application_id', $application->id)->first();
        $this->assertSame("{$date} 00:00", $detail->interview_date->format('Y-m-d H:i'));
        $this->assertSame("{$date} 12:00", $detail->interview_ends_at->format('Y-m-d H:i'));
    }

    /**
     * Confirmed explicitly 2026-11-10: "yang minit tu tak kisah lah nak
     * tulis berapa pun" -- minute is free entry, 0-59, not stepped. A
     * leading-zero minute ("05") is the one value a naive 'integer' rule
     * gets wrong: PHP's filter_var(FILTER_VALIDATE_INT) rejects a
     * leading zero as looking like octal and returns false, which would
     * make every single-digit minute un-submittable. is_numeric() (the
     * 'numeric' rule) has no such quirk.
     */
    public function test_a_leading_zero_minute_is_accepted(): void
    {
        $reviewer = $this->researchCentreUser('Computing');
        $application = $this->gaApplicationAtResearchCentreStage('Computing');
        $date = now()->addDay()->format('Y-m-d');

        $this->actingAs($reviewer)
            ->post(route('ga-application.schedule-interview', $application), $this->validRequest([
                'interview_date' => $date,
                'interview_start_minute' => '05',
                'interview_end_minute' => '09',
            ]))
            ->assertSessionHasNoErrors();

        $detail = GaApplicationDetail::where('application_id', $application->id)->first();
        $this->assertSame("{$date} 14:05", $detail->interview_date->format('Y-m-d H:i'));
        $this->assertSame("{$date} 16:09", $detail->interview_ends_at->format('Y-m-d H:i'));
    }
}
