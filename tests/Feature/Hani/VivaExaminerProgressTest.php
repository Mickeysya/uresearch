<?php

namespace Tests\Feature\Hani;

use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaExaminerProgress;
use App\Modules\Hani\Models\VivaReminderLog;
use App\Modules\Hani\Notifications\PanelSeatChanged;
use App\Modules\Hani\Notifications\VivaReminder;
use App\Modules\Hani\Notifications\VivaScheduled;
use App\Modules\Hani\Support\ExaminerDivergence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Hani\Concerns\BuildsVivas;
use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Examiner progress: one screen per student, a rejection promoting the
 * backup, the reminders sent once, and the divergence alert.
 */
class VivaExaminerProgressTest extends TestCase
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
    }

    protected function rows(array $viva)
    {
        $this->actingAs($viva['ae'])->get(route('viva.examiners.show', $viva['notice']))->assertOk();

        return VivaExaminerProgress::current()->where('viva_application_id', $viva['notice']->id)->get()->keyBy('seat');
    }

    public function test_one_screen_shows_the_current_panel_and_records_each_step(): void
    {
        $viva = $this->viva();
        $rows = $this->rows($viva);

        $this->assertEqualsCanonicalizing(['internal_main_id', 'external_main_id'], $rows->keys()->all());
        $this->assertSame($viva['panel']->internal_main_id, $rows['internal_main_id']->examiner_id);

        $row = $rows['internal_main_id'];
        $post = fn (array $data) => $this->actingAs($viva['ae'])->post(route('viva.examiners.update', [$viva['notice'], $row]), $data);

        // Out of order is refused.
        $post(['thesis_received_at' => now()->toDateString()])->assertSessionHasErrors('thesis_received_at');

        $post(['letter_sent_at' => now()->subDays(3)->toDateString()])->assertSessionHasNoErrors();
        $post(['acknowledgement' => 'accepted', 'acknowledged_at' => now()->subDay()->toDateString()])->assertSessionHasNoErrors();
        $post(['thesis_received_at' => now()->toDateString()])->assertSessionHasNoErrors();
        $post(['report_received_at' => now()->toDateString()])->assertSessionHasErrors('category');
        $post([
            'report_received_at' => now()->toDateString(), 'category' => 2,
            'report' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
            'ratings' => ['criteria' => ['originality' => 4], 'sections' => ['methodology' => 3]],
        ])->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame('Report returned', $row->stage());
        $this->assertSame(2, $row->category);
        $this->assertSame(['criteria' => ['originality' => 4], 'sections' => ['methodology' => 3]], $row->ratings);
        $this->assertStringStartsWith('hani/examiner-reports/', $row->report_path);
        Storage::disk('local')->assertExists($row->report_path);

        // The report is staff-only and downloadable by the AE.
        $this->actingAs($viva['ae'])->get(route('viva.examiners.report', [$viva['notice'], $row]))->assertOk();
        $this->actingAs($viva['student'])->get(route('viva.examiners.report', [$viva['notice'], $row]))->assertForbidden();
        $this->actingAs($viva['student'])->get(route('viva.examiners.show', $viva['notice']))->assertForbidden();
        $this->actingAs($viva['student'])->get(route('documents.index'))->assertOk()->assertDontSee('report.pdf');

        // Another department's AE cannot open it.
        $other = $this->academicExecFor('Chemical Engineering');
        $this->actingAs($other)->get(route('viva.examiners.show', $viva['notice']))->assertForbidden();
    }

    public function test_a_rejected_acknowledgement_promotes_the_backup(): void
    {
        $viva = $this->viva();
        $row = $this->rows($viva)['external_main_id'];
        $backupId = $viva['panel']->external_backup_id;
        User::create(['name' => 'Encik Syahmi', 'email' => 'syahmi@test.my', 'password' => 'password', 'role' => 'non_exec_cgs']);

        $this->actingAs($viva['ae'])->post(route('viva.examiners.update', [$viva['notice'], $row]), [
            'acknowledgement' => 'rejected', 'acknowledged_at' => now()->toDateString(),
        ])->assertSessionHasErrors('rejection_reason');

        $this->actingAs($viva['ae'])->post(route('viva.examiners.update', [$viva['notice'], $row]), [
            'acknowledgement' => 'rejected', 'acknowledged_at' => now()->toDateString(), 'rejection_reason' => 'On sabbatical leave',
        ])->assertSessionHas('status', fn ($m) => str_contains($m, 'has been promoted'));

        $panel = $viva['panel']->fresh();
        $this->assertSame($backupId, $panel->external_main_id);
        $this->assertNull($panel->external_backup_id);

        $row->refresh();
        $this->assertNotNull($row->superseded_at);
        $this->assertSame('rejected', $row->acknowledgement);

        $current = $this->rows($viva);
        $this->assertSame($backupId, $current['external_main_id']->examiner_id);
        Notification::assertSentTo(User::where('role', 'non_exec_cgs')->sole(), PanelSeatChanged::class);

        $this->actingAs($viva['ae'])->get(route('viva.examiners.show', $viva['notice']))
            ->assertSee('Examiners who left the panel')
            ->assertSee('On sabbatical leave');
    }

    public function test_the_no_reply_chaser_and_overdue_report_are_sent_once(): void
    {
        $msc = $this->viva('22001001');
        $phd = $this->viva('22001002', 'PhD Full-Time');
        $mscRows = $this->rows($msc);
        $phdRows = $this->rows($phd);

        // MSc internal: letter 15 days ago, no reply -> chaser.
        $mscRows['internal_main_id']->update(['letter_sent_at' => now()->subDays(15)]);
        // MSc external: thesis received 29 days ago, no report -> overdue (4 weeks).
        $mscRows['external_main_id']->update(['letter_sent_at' => now()->subDays(40), 'acknowledgement' => 'accepted', 'acknowledged_at' => now()->subDays(35), 'thesis_received_at' => now()->subDays(29)]);
        // PhD external: same 29 days, but a PhD report is due after 6 weeks -> nothing yet.
        $phdRows['external_main_id']->update(['letter_sent_at' => now()->subDays(40), 'acknowledgement' => 'accepted', 'acknowledged_at' => now()->subDays(35), 'thesis_received_at' => now()->subDays(29)]);
        // PhD internal: letter 10 days ago -> too soon to chase.
        $phdRows['internal_main_id']->update(['letter_sent_at' => now()->subDays(10)]);

        $this->artisan('viva:reminders', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, VivaReminderLog::count());
        Notification::assertNotSentTo($msc['ae'], VivaReminder::class);

        $this->artisan('viva:reminders')->assertSuccessful();
        $this->artisan('viva:reminders')->assertSuccessful();

        $this->assertSame(['ack_chaser', 'report_overdue'], VivaReminderLog::orderBy('kind')->pluck('kind')->all());
        Notification::assertSentToTimes($msc['ae'], VivaReminder::class, 2);
        Notification::assertSentTo($msc['ae'], VivaReminder::class, fn ($n) => $n->kind === 'ack_chaser');
        Notification::assertSentTo($msc['ae'], VivaReminder::class, fn ($n) => $n->kind === 'report_overdue');
        Notification::assertNotSentTo($msc['student'], VivaReminder::class);
    }

    public function test_diverging_categories_are_flagged_minor_and_major(): void
    {
        $viva = $this->viva();
        $rows = $this->rows($viva);

        $this->assertNull(ExaminerDivergence::between($rows));

        $rows['internal_main_id']->update(['category' => 2]);
        $rows['external_main_id']->update(['category' => 3]);
        $this->assertSame('minor', ExaminerDivergence::between($rows->map->fresh())['level']);

        $rows['external_main_id']->update(['category' => 4]);
        $divergence = ExaminerDivergence::between($rows->map->fresh());
        $this->assertSame('major', $divergence['level']);
        $this->assertSame('Internal: 2 (Minor) · External: 4 (Re-examination)', $divergence['text']);

        // On the scheduling list and the Set Date form, for staff.
        $this->actingAs($viva['ae'])->get(route('viva.schedule.index'))
            ->assertSee('Major divergence')
            ->assertSee('Internal: 2 (Minor) · External: 4 (Re-examination)');
        $this->actingAs($viva['ae'])->get(route('viva.schedule.edit', $viva['notice']))
            ->assertSee('Internal: 2 (Minor) · External: 4 (Re-examination)');

        // Never to the student.
        $this->actingAs($viva['student'])->get(route('viva.eligibility'))
            ->assertDontSee('Re-examination')
            ->assertDontSee($rows['internal_main_id']->examiner->name);

        // The chairman alone gets the neutral line, without categories.
        $this->actingAs($viva['ae'])->post(route('viva.schedule.update', $viva['notice']), [
            'viva_date' => now()->next('Tuesday')->addWeeks(2)->toDateString(), 'viva_time' => '10:00',
            'venue' => 'Room 1', 'chair_name' => 'Prof Chair', 'chair_email' => 'chair@example.com',
            'override_warnings' => 1,
        ])->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(VivaScheduled::class, function ($n, $channels, AnonymousNotifiable $to) {
            $mail = implode(' ', $n->toMail($to)->introLines);
            $isChair = array_key_exists('chair@example.com', (array) $to->routes['mail']);

            return $isChair === str_contains($mail, 'discuss them with the panel at the pre-viva meeting')
                && ! str_contains($mail, 'Re-examination');
        });
    }
}
