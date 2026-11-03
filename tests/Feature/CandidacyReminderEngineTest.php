<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\CandidacyReminderLog;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyExpiryReminder;
use App\Modules\Chloe\Support\CandidacyReminderEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Stage C: the reminder engine -- milestones, de-duplication, stops, pauses, variants, research only, the job. */
class CandidacyReminderEngineTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function today(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 08:00:00', 'Asia/Kuala_Lumpur'));
    }

    protected function setExpiry(string $date): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => $date]);
    }

    /** Run the real job (for "today") and return how many reminders the student got. */
    protected function job(): int
    {
        Notification::fake();
        $this->artisan('candidacy:send-reminders')->assertSuccessful();

        return Notification::sent($this->student, CandidacyExpiryReminder::class)->count();
    }

    // ---- milestone dates ---------------------------------------------------------------------------------------

    public function test_milestone_dates_are_calendar_months_without_overflow(): void
    {
        $dates = fn (string $expiry) => array_map(fn ($d) => $d->toDateString(), CandidacyReminderEngine::dates(CarbonImmutable::parse($expiry)));

        $this->assertSame([3 => '2026-02-28', 2 => '2026-03-31', 1 => '2026-04-30'], $dates('2026-05-31'));
        $this->assertSame([3 => '2028-02-29', 2 => '2028-03-31', 1 => '2028-04-30'], $dates('2028-05-31'), 'a leap year');
        $this->assertSame([3 => '2026-09-30', 2 => '2026-10-31', 1 => '2026-11-30'], $dates('2026-12-31'));
        $this->assertSame([3 => '2026-11-28', 2 => '2026-12-28', 1 => '2027-01-28'], $dates('2027-02-28'));
    }

    // ---- sending, once -----------------------------------------------------------------------------------------

    public function test_a_milestone_goes_out_on_its_date_and_not_the_day_before(): void
    {
        $this->setExpiry('2027-05-31');

        $this->today('2027-02-27');
        $this->assertSame(0, $this->job());

        $this->today('2027-02-28'); // 31 May minus 3 months: the last day of February
        $this->assertSame(1, $this->job());
        $this->assertSame([3], CandidacyReminderLog::where('status', 'sent')->pluck('milestone')->all());
    }

    public function test_running_the_job_twice_sends_nothing_twice(): void
    {
        $this->setExpiry('2027-05-31');
        $this->today('2027-02-28');

        $this->assertSame(1, $this->job());
        $this->assertSame(0, $this->job());
        $this->assertSame(1, CandidacyReminderLog::count());
    }

    public function test_the_next_milestones_follow_on_their_own_dates(): void
    {
        $this->setExpiry('2027-05-31');
        $sent = [];

        foreach (['2027-02-28' => 3, '2027-03-30' => 0, '2027-03-31' => 2, '2027-04-30' => 1, '2027-05-01' => 0] as $day => $expected) {
            $this->today($day);
            $this->assertSame($expected > 0 ? 1 : 0, $this->job(), $day);
            $expected && $sent[] = $expected;
        }

        $this->assertSame([3, 2, 1], $sent);
    }

    public function test_a_new_expiry_date_starts_a_fresh_cycle(): void
    {
        $this->setExpiry('2027-05-31');
        $this->today('2027-02-28');
        $this->assertSame(1, $this->job());

        $this->setExpiry('2027-11-30'); // e.g. an approved appeal or a CGS adjustment
        $this->today('2027-08-30');
        $this->assertSame(1, $this->job(), 'the 3-month milestone of the new date is not blocked by the old cycle');
        $this->assertSame(2, CandidacyReminderLog::where('status', 'sent')->count());
        $this->assertSame(['2027-05-31', '2027-11-30'], CandidacyReminderLog::orderBy('id')->get()->map(fn ($r) => $r->expiry_date->toDateString())->all());
    }

    public function test_a_late_entry_sends_only_the_latest_due_milestone_and_records_the_earlier_ones_as_skipped(): void
    {
        $this->setExpiry('2027-05-31');
        $this->today('2027-04-30'); // 3, 2 and 1 month are all due and unsent (the student entered the window late)

        $this->assertSame(1, $this->job());
        $rows = CandidacyReminderLog::orderBy('milestone')->get()->map(fn ($r) => "{$r->milestone}:{$r->status}")->all();
        $this->assertSame(['1:sent', '2:skipped', '3:skipped'], $rows);

        foreach (['2027-05-01', '2027-05-20'] as $later) { // the skipped ones never send later
            $this->today($later);
            $this->assertSame(0, $this->job(), $later);
        }
    }

    public function test_the_expiry_day_itself_is_still_inside_the_window_and_the_day_after_is_not(): void
    {
        $this->setExpiry('2027-05-31');

        $this->today('2027-05-31');
        $this->assertSame(1, $this->job());

        CandidacyReminderLog::query()->delete();
        $this->today('2027-06-01');
        $this->assertSame(0, $this->job());
    }

    // ---- stops and pauses --------------------------------------------------------------------------------------

    public function test_every_stop_rule(): void
    {
        $this->setExpiry('2027-05-31');
        $this->today('2027-02-28');

        foreach (['softbound', 'completed', 'inactive', 'dismissed'] as $status) {
            $this->candidacy->update(['status' => $status]);
            $this->assertSame(0, $this->job(), $status);
        }
        $this->candidacy->update(['status' => 'dismissed', 'dismissed_at' => now()]);
        $this->assertSame(0, $this->job(), 'dismissed_at');

        $this->candidacy->update(['status' => 'active', 'dismissed_at' => null]);
        $this->assertSame(1, $this->job(), 'and an active student gets it');
    }

    public function test_coursework_students_get_nothing_and_part_time_research_students_do(): void
    {
        $this->setExpiry('2027-05-31');
        $this->today('2027-02-28');

        $this->student->update(['programme' => 'MSc Software Engineering (Coursework)']);
        $this->assertSame(0, $this->job());

        $this->student->update(['programme' => 'PhD Computer Science (Part-Time)']);
        $this->assertSame(1, $this->job());
    }

    public function test_an_appeal_in_review_pauses_and_the_reminders_resume_afterwards(): void
    {
        $this->setExpiry('2027-05-31');
        $appeal = $this->submitted(2);
        $this->today('2027-02-28');
        $this->assertSame(0, $this->job(), 'with the supervisor: paused');

        $this->service->decide($this->lecturer, $appeal, 'approve');
        $this->assertSame(0, $this->job(), 'with the chair: paused');
        $this->assertSame(0, CandidacyReminderLog::count(), 'a pause records nothing');

        $this->today('2027-03-31');
        $appeal = $this->service->decide($this->chairUser, $appeal->fresh(), 'return', ['documents_missing']);
        $this->assertSame(1, $this->job(), 'returned: variant C resumes, and as a late entry only the latest is sent');
        $this->assertSame('C', CandidacyReminderLog::where('status', 'sent')->value('variant'));
        $this->assertSame(['3:skipped', '2:sent'], CandidacyReminderLog::orderBy('milestone', 'desc')->get()->map(fn ($r) => "{$r->milestone}:{$r->status}")->all());
    }

    public function test_reminders_resume_after_a_rejection_and_after_an_approval(): void
    {
        $this->setExpiry('2027-05-31');
        $this->today('2027-02-28');
        $appeal = $this->toStage('dean', 2);
        $this->assertSame(0, $this->job(), 'with the Dean: paused');

        $this->service->decide($this->deanUser, $appeal, 'reject', ['plan_not_realistic']);
        $this->assertSame(1, $this->job(), 'rejected: back on (variant B)');
        $this->assertSame('B', CandidacyReminderLog::where('status', 'sent')->value('variant'));
    }

    public function test_an_approved_appeal_moves_the_expiry_date_and_the_cycle_starts_again(): void
    {
        $this->setExpiry('2027-05-31');
        $appeal = $this->toStage('dean', 2);
        $this->service->decide($this->deanUser, $appeal, 'approve'); // new expiry 2027-07-31

        $this->today('2027-04-30'); // 3 months before the NEW date
        $this->assertSame(1, $this->job());
        $this->assertSame('2027-07-31', CandidacyReminderLog::first()->expiry_date->toDateString());
    }

    // ---- variants and content ----------------------------------------------------------------------------------

    protected function sent(string $day): CandidacyExpiryReminder
    {
        $this->today($day);
        Notification::fake();
        $this->artisan('candidacy:send-reminders')->assertSuccessful();

        return Notification::sent($this->student, CandidacyExpiryReminder::class)->first();
    }

    public function test_variant_a_content(): void
    {
        $this->setExpiry('2027-05-31');
        $n = $this->sent('2027-03-31');
        $mail = $n->toMail($this->student);
        $text = implode("\n", $mail->introLines).' '.implode("\n", $mail->outroLines);

        $this->assertSame('A', $n->variant);
        $this->assertSame('Study Candidacy expiry reminder: 2 month(s) left (expires 31 May 2027)', $mail->subject);
        $this->assertSame('Dear Ahmad Danial,', $mail->greeting);
        $this->assertStringContainsString('Your candidacy period ends on 31 May 2027 (2 month(s) from now). Before that date you can:', $text);
        $this->assertStringContainsString('1) Submit your soft-bound thesis (the candidacy period ends at Softbound submission).', $text);
        $this->assertStringContainsString('2) Apply for an extension. The appeal must be submitted on or before 31 May 2027; you have 12 of 12 months left.', $text);
        $this->assertStringContainsString('If you have already done this, please ignore this message.', $text);
        $this->assertStringContainsString('section 4.3', $text);
        $this->assertStringContainsString('Contact CGS if you need help.', $text);
        $this->assertSame('Open Study Candidacy', $mail->actionText);
        $this->assertSame(route('candidacy.status'), $mail->actionUrl);
    }

    public function test_variant_b_content_for_both_reasons_and_the_config_switch(): void
    {
        $this->setExpiry('2027-05-31');
        $this->candidacy->update(['extension_months_used' => 12]);
        $n = $this->sent('2027-04-30');
        $text = implode("\n", $n->toMail($this->student)->introLines);

        $this->assertSame('B', $n->variant);
        $this->assertSame('Study Candidacy expiry reminder: 1 month(s) left (expires 31 May 2027)', $n->subject());
        $this->assertStringContainsString('2) No further extension appeal can be submitted for this candidacy (the 12-month extension allowance has been used). Please submit your soft-bound thesis on or before 31 May 2027.', $text);
        $this->assertStringNotContainsString('Apply for an extension', $text);

        CandidacyReminderLog::query()->delete();
        $this->candidacy->update(['extension_months_used' => 0, 'last_rejection_at' => now()]);
        $this->assertStringContainsString('(your last appeal was rejected)', implode("\n", $this->sent('2027-04-30')->toMail($this->student)->introLines));

        CandidacyReminderLog::query()->delete();
        config(['candidacy.reminders.send_when_appeal_blocked' => false]);
        $this->assertSame(0, $this->job(), 'switched off for variant B');
    }

    public function test_variant_c_content(): void
    {
        $this->setExpiry('2027-05-31');
        $appeal = $this->service->decide($this->lecturer, $this->submitted(2), 'return', ['documents_missing', 'other'], 'Add the transcript.');
        $n = $this->sent('2027-03-31');
        $mail = $n->toMail($this->student);
        $text = implode("\n", $mail->introLines);

        $this->assertSame('C', $n->variant);
        $this->assertSame('Action needed: your extension appeal was returned (candidacy expires 31 May 2027)', $mail->subject);
        $this->assertStringContainsString("Your extension appeal {$appeal->appeal_no} was returned to you at the Supervisor / Programme Manager step.", $text);
        $this->assertStringContainsString('Supporting documents missing', $text);
        $this->assertStringContainsString('Add the transcript.', $text);
        $this->assertStringContainsString('resubmit it on or before 31 May 2027', $text);
        $this->assertSame('Open appeal', $mail->actionText);
        $this->assertSame(route('candidacy.appeals.show', $appeal), $mail->actionUrl);
    }

    public function test_the_bell_carries_the_title_and_a_link_and_a_demo_student_gets_e_mail_only(): void
    {
        $this->setExpiry('2027-05-31');
        $n = $this->sent('2027-02-28');

        $this->assertSame(['mail', 'database'], $n->via($this->student));
        $bell = $n->toArray($this->student);
        $this->assertSame('Study Candidacy expiry reminder: 3 month(s) left (expires 31 May 2027)', $bell['title']);
        $this->assertSame(route('candidacy.status'), $bell['url']);
        $this->assertSame(['mail'], $n->via(new \Illuminate\Notifications\AnonymousNotifiable));
    }

    // ---- demo students -----------------------------------------------------------------------------------------

    protected function demoStudent(string $id, string $expiry, string $programme = 'PhD X (Full-Time)'): DemoStudentProfile
    {
        return DemoStudentProfile::create(['matric_no' => $id, 'name' => "Demo {$id}", 'email' => "d{$id}@utp.edu.my", 'gender' => 'male', 'programme' => $programme, 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => $expiry, 'status' => 'active']);
    }

    public function test_demo_students_are_reminded_by_e_mail_and_the_overlay_can_stop_them(): void
    {
        $this->setExpiry('2028-12-31');
        $this->demoStudent('21011001', '2027-05-31');
        $stopped = $this->demoStudent('21011002', '2027-05-31');
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $stopped->id, 'softbound_submitted_at' => '2027-01-10']);
        $this->demoStudent('21011003', '2027-05-31', 'MSc Y (Coursework)');
        $this->today('2027-02-28');

        Notification::fake();
        $this->artisan('candidacy:send-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(CandidacyExpiryReminder::class, 1);
        $this->assertSame(1, CandidacyReminderLog::whereNotNull('demo_profile_id')->where('status', 'sent')->count());
        $this->assertSame(0, CandidacyReminderLog::where('demo_profile_id', $stopped->id)->count());

        config(['candidacy.reminders.demo_students' => false]);
        CandidacyReminderLog::query()->delete();
        Notification::fake();
        $this->artisan('candidacy:send-reminders')->assertSuccessful();
        Notification::assertSentOnDemandTimes(CandidacyExpiryReminder::class, 0);
    }

    // ---- the command and the job -------------------------------------------------------------------------------

    public function test_dry_run_prints_who_would_get_what_and_writes_and_sends_nothing(): void
    {
        $this->setExpiry('2027-05-31');
        Notification::fake();

        $this->artisan('candidacy:send-reminders', ['--dry-run' => true, '--date' => '2027-02-28'])
            ->expectsOutputToContain('WOULD SEND milestone 3 month(s), variant A: 22001001 Ahmad Danial expires 2027-05-31')->assertSuccessful();

        $this->assertSame(0, CandidacyReminderLog::count());
        Notification::assertNothingSent();
    }

    public function test_the_date_option_needs_dry_run_or_the_local_environment(): void
    {
        $this->setExpiry('2027-05-31');
        Notification::fake();

        $this->artisan('candidacy:send-reminders', ['--date' => '2027-02-28'])->expectsOutputToContain('--date can only be used with --dry-run')->assertFailed();
        $this->assertSame(0, CandidacyReminderLog::count());
        $this->artisan('candidacy:send-reminders', ['--date' => 'not-a-date', '--dry-run' => true])->assertFailed();
        $this->artisan('candidacy:send-reminders', ['--date' => '2027-02-30', '--dry-run' => true])->assertFailed();

        $this->app['env'] = 'local';
        $this->artisan('candidacy:send-reminders', ['--date' => '2027-02-28'])->assertSuccessful();
        $this->assertSame(1, CandidacyReminderLog::count());
    }

    public function test_the_job_is_scheduled_at_0725_in_kuala_lumpur_after_the_close_job(): void
    {
        $events = collect(app(Schedule::class)->events());
        $close = $events->first(fn ($e) => str_contains($e->command, 'candidacy:close-expired-appeals'));
        $remind = $events->first(fn ($e) => str_contains($e->command, 'candidacy:send-reminders'));

        $this->assertSame('30 7 * * *', $close->expression);
        $this->assertSame('0 8 * * *', $remind->expression);
        $this->assertSame('Asia/Kuala_Lumpur', (string) $remind->timezone);
    }

    public function test_the_old_command_sends_nothing(): void
    {
        $this->setExpiry('2027-05-31');
        $this->today('2027-02-28');
        Notification::fake();

        $this->artisan('candidacy:remind')->expectsOutputToContain('deprecated')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, CandidacyReminderLog::count());
    }

    public function test_demo_clear_removes_the_demo_reminder_rows_only(): void
    {
        $this->setExpiry('2027-05-31');
        $this->demoStudent('21011010', '2027-05-31');
        $this->today('2027-02-28');
        Notification::fake();
        $this->artisan('candidacy:send-reminders')->assertSuccessful();
        $this->assertSame(2, CandidacyReminderLog::count());

        $this->artisan('candidacy:demo-clear')->assertSuccessful();

        $this->assertSame(1, CandidacyReminderLog::count());
        $this->assertNotNull(CandidacyReminderLog::first()->candidacy_id);
    }
}
