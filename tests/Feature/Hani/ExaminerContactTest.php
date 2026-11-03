<?php

namespace Tests\Feature\Hani;

use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerContactHistory;
use App\Modules\Hani\Notifications\ExaminerEmailReported;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Notifications\VivaScheduled;
use App\Modules\Hani\Support\Matching\ExaminerSuggestions;
use App\Modules\Hani\Support\ReleasedExaminerProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Keeping examiner emails current: the Non-Exec email-only edit, the
 * contact history, the "not working" flag, freshness, and emails proposed
 * at nomination.
 */
class ExaminerContactTest extends TestCase
{
    use BuildsVivas;
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
        config(['hani.scheduling.public_holidays' => []]);
        Carbon::setTestNow('2027-01-04 08:00');   // a Monday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function schedule(array $viva, string $date = '2027-01-14')
    {
        return $this->actingAs($viva['ae'])->post(route('viva.schedule.update', $viva['notice']), [
            'viva_date' => $date, 'viva_time' => '10:00', 'venue' => 'Room 1', 'chair_name' => 'Prof Chair',
            'chair_email' => 'chair@example.com', 'override_warnings' => 1,
        ]);
    }

    /** Was the viva invitation emailed to this address? */
    protected function invited(string $email, string $class = VivaScheduled::class): bool
    {
        foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byNotifiable) {
            foreach ($byNotifiable[$class] ?? [] as $sent) {
                if (array_key_exists($email, (array) $sent['notifiable']->routes['mail'])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function external(array $attributes = []): Examiner
    {
        return Examiner::create($attributes + [
            'name' => 'Prof Dr Zainal Ariffin',
            'email' => 'zainal@old.example.com',
            'department' => 'Mechanical Engineering',
            'type' => Examiner::TYPE_EXTERNAL,
            'institution' => 'Example University of Technology',
            'is_active' => true,
        ]);
    }

    public function test_non_exec_can_change_the_email_and_nothing_else(): void
    {
        $examiner = $this->external();
        $nonExec = $this->cgs();

        $this->actingAs($nonExec)->get(route('examiner-admin.show', $examiner))->assertOk()->assertSee('Update email')->assertDontSee('Edit examiner');
        $this->actingAs($nonExec)->get(route('examiner-admin.email.edit', $examiner))->assertOk();

        // Everything else posted alongside is ignored.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), [
            'email' => 'Zainal@NEW.example.com',
            'reason' => 'New address from the university staff page',
            'name' => 'Someone Else',
            'department' => 'Petroleum Engineering',
            'is_active' => 0,
        ])->assertRedirect(route('examiner-admin.show', $examiner))->assertSessionHasNoErrors();

        $examiner->refresh();
        $this->assertSame('zainal@new.example.com', $examiner->email);
        $this->assertSame('Prof Dr Zainal Ariffin', $examiner->name);
        $this->assertSame('Mechanical Engineering', $examiner->department);
        $this->assertTrue($examiner->is_active);

        // The full form stays the Senior Executive's.
        $this->actingAs($nonExec)->put(route('examiner-admin.update', $examiner), ['name' => 'X'])->assertForbidden();

        // A reason is required, and an address another examiner has is refused.
        $this->external(['name' => 'Dr Other', 'email' => 'other@example.com']);
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), ['email' => 'x@example.com'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), ['email' => 'other@example.com', 'reason' => 'x'])
            ->assertSessionHasErrors('email');

        // Others cannot use it at all.
        $this->actingAs($this->supervisor())->put(route('examiner-admin.email.update', $examiner), ['email' => 'x@example.com', 'reason' => 'x'])->assertForbidden();
    }

    public function test_every_email_change_is_logged_and_shown_on_the_examiner_page(): void
    {
        $senior = $this->seniorExec();
        $nonExec = $this->cgs();

        // Added with an email on the full form.
        $this->actingAs($senior)->post(route('examiner-admin.store'), [
            'name' => 'Dr Farah Hanim', 'email' => 'farah@first.example.com', 'department' => 'Computing', 'type' => 'internal',
        ])->assertSessionHasNoErrors();
        $examiner = Examiner::where('name', 'Dr Farah Hanim')->firstOrFail();

        // Changed by Non-Exec, then by the Senior Exec on the full form.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $examiner), [
            'email' => 'farah@second.example.com', 'reason' => 'Bounced; new address from her department',
        ]);
        $this->actingAs($senior)->put(route('examiner-admin.update', $examiner), [
            'name' => 'Dr Farah Hanim', 'email' => 'farah@third.example.com', 'department' => 'Computing', 'type' => 'internal',
            'email_change_reason' => 'Moved office',
        ])->assertSessionHasNoErrors();

        // Saving the form without touching the email logs nothing.
        $this->actingAs($senior)->put(route('examiner-admin.update', $examiner), [
            'name' => 'Dr Farah Hanim Binti Yusof', 'email' => 'farah@third.example.com', 'department' => 'Computing', 'type' => 'internal',
        ]);

        $lines = ExaminerContactHistory::where('examiner_id', $examiner->id)->orderBy('id')->get();
        $this->assertSame([
            [null, 'farah@first.example.com', 'manual', 'Senior Executive CGS'],
            ['farah@first.example.com', 'farah@second.example.com', 'email_form', 'Non-Executive CGS'],
            ['farah@second.example.com', 'farah@third.example.com', 'manual', 'Senior Executive CGS'],
        ], $lines->map(fn ($l) => [$l->old_email, $l->new_email, $l->source, \App\Modules\Core\Support\Role::label($l->changedBy->role)])->all());
        $this->assertSame('Bounced; new address from her department', $lines[1]->reason);

        $this->actingAs($nonExec)->get(route('examiner-admin.show', $examiner))
            ->assertOk()
            ->assertSee('Contact history')
            ->assertSee('farah@first.example.com')
            ->assertSee('Bounced; new address from her department')
            ->assertSee('M Syahmi Ifwat M Jafri');
    }

    public function test_an_academic_executive_reports_an_email_not_working_and_cgs_is_told(): void
    {
        $viva = $this->viva();
        $external = $viva['panel']->externalMain;
        $nonExec = $this->cgs();
        $senior = $this->seniorExec();

        // From the viva examiner-progress screen.
        $this->actingAs($viva['ae'])->get(route('viva.examiners.show', $viva['notice']))->assertOk()->assertSee('Report email not working');
        $this->actingAs($viva['ae'])->from(route('viva.examiners.show', $viva['notice']))
            ->post(route('examiner-admin.report-email', $external), [])
            ->assertSessionHasErrors('reason');
        $this->actingAs($viva['ae'])->from(route('viva.examiners.show', $viva['notice']))
            ->post(route('examiner-admin.report-email', $external), ['reason' => 'Invitation bounced: mailbox does not exist'])
            ->assertRedirect(route('viva.examiners.show', $viva['notice']));

        $external->refresh();
        $this->assertSame(Examiner::EMAIL_INVALID, $external->emailStatus());
        $this->assertNotNull($external->email_flagged_at);
        $this->assertSame('external_main.22001001@example.com', $external->email, 'The address is kept, only flagged.');
        $line = ExaminerContactHistory::where('examiner_id', $external->id)->sole();
        $this->assertSame(['reported', 'Invitation bounced: mailbox does not exist', $viva['ae']->id], [$line->source, $line->reason, $line->changed_by_id]);

        Notification::assertSentTo([$nonExec, $senior], ExaminerEmailReported::class);
        Notification::assertNotSentTo($viva['ae'], ExaminerEmailReported::class);

        // Badges: the pool, the examiner page, the viva screen, the panel.
        $this->actingAs($nonExec)->get(route('examiner-admin.index'))->assertSee('Email needs updating');
        $this->actingAs($viva['ae'])->get(route('examiner-admin.show', $external))->assertOk()->assertSee('Email needs updating')->assertSee('reported not working');
        $this->actingAs($viva['ae'])->get(route('viva.examiners.show', $viva['notice']))->assertSee('Email needs updating');
        $this->actingAs($senior)->get(route('examiner-nomination.report'))->assertOk()->assertSee('Email needs updating');

        // An AE from another department may neither open nor report this examiner.
        $other = $this->academicExecFor('Petroleum Engineering');
        $this->actingAs($other)->get(route('examiner-admin.show', $external))->assertForbidden();
        $this->actingAs($other)->post(route('examiner-admin.report-email', $viva['panel']->internalMain), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->supervisor())->post(route('examiner-admin.report-email', $external), ['reason' => 'x'])->assertForbidden();
    }

    public function test_the_badge_shows_on_the_nomination_form_and_in_the_suggestions(): void
    {
        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id, 'department' => 'Computing']);
        $examiner = $this->external(['department' => 'Computing']);
        $examiner->expertiseKeywords()->create(['keyword' => 'anomaly detection']);
        $examiner->update(['email_status' => Examiner::EMAIL_INVALID, 'email_flagged_at' => now()]);

        $this->actingAs($supervisor)->get(route('examiner-nomination.create'))->assertOk()->assertSee('[Email needs updating]');

        $suggested = collect(app(ExaminerSuggestions::class)->forStudent($student, 'Anomaly detection for sensor data')['external'])->firstWhere('id', $examiner->id);
        $this->assertContains('Email needs updating: reported not working.', array_column($suggested['flags'], 'message'));
    }

    public function test_an_invalid_email_blocks_invitations_reminders_and_letters_until_it_is_updated(): void
    {
        $viva = $this->viva();
        $external = $viva['panel']->externalMain;
        $address = $external->email;
        $nonExec = $this->cgs();
        app(\App\Modules\Hani\Support\ExaminerContact::class)->report($external, $nonExec, 'Bounced');

        // The viva invitation goes to everyone else, and the AE is told why not to the examiner.
        $this->schedule($viva)->assertSessionHas('warning', fn ($w) => str_contains($w, "{$external->name}'s email was reported not working"));
        $this->assertFalse($this->invited($address));
        $this->assertTrue($this->invited('internal_main.22001001@example.com'));

        // The panel reminders skip them too.
        Carbon::setTestNow('2027-01-07 07:45');
        $this->artisan('viva:reminders')->expectsOutputToContain('examiner skipped')->assertSuccessful();
        $this->assertFalse($this->invited($address, VivaReminder::class));

        // The appointment letter module gets no address, and the reason.
        $panel = app(ReleasedExaminerProvider::class)->panelForStudent($viva['student']->id);
        $this->assertNull($panel['examiners']['external_main']['email']);
        $this->assertStringContainsString('reported not working', $panel['examiners']['external_main']['email_problem']);
        $this->assertSame('internal_main.22001001@example.com', $panel['examiners']['internal_main']['email']);

        // Updating the email clears the flag, and the next invitation reaches them.
        $this->actingAs($nonExec)->put(route('examiner-admin.email.update', $external), [
            'email' => 'new.address@example.com', 'reason' => 'From the university staff directory',
        ])->assertSessionHasNoErrors();
        $external->refresh();
        $this->assertSame(Examiner::EMAIL_OK, $external->emailStatus());
        $this->assertNull($external->email_flagged_at);

        $this->schedule($viva, '2027-01-21')->assertSessionMissing('warning');
        $this->assertTrue($this->invited('new.address@example.com'));
    }
}
