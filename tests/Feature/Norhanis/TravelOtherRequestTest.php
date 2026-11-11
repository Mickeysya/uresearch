<?php

namespace Tests\Feature\Norhanis;

use App\Modules\Core\Models\Application;
use App\Modules\Norhanis\Models\TravelDetail;
use App\Modules\Norhanis\Notifications\ApplicationAwaitingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Travel's "If 'Others', please specify" (UAT, 11 Nov 2026): shown and
 * required only when Type of Request is Others; hidden, emptied and not
 * validated otherwise, on the form and on the server. Shown on the
 * application page, the summary PDF and the approver's email only when it
 * holds something, and refilled when a draft is continued.
 */
class TravelOtherRequestTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    public function test_others_requires_the_description(): void
    {
        $this->actingAs($this->student())
            ->post(route('travel.store'), $this->payload(['type_of_request' => 'others']))
            ->assertSessionHasErrors(['other_request_specify' => 'Please describe the request when choosing "Others".']);

        $this->assertSame(0, TravelDetail::count());

        $this->actingAs($this->student('22001002', 's2@test.my'))
            ->post(route('travel.store'), $this->payload(['type_of_request' => 'others', 'other_request_specify' => 'Industry visit']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Industry visit', TravelDetail::sole()->other_request_specify);
    }

    public function test_any_other_type_ignores_the_field_and_keeps_nothing(): void
    {
        // Even a value no rule would accept is not validated: the field is
        // dropped for every type but Others.
        $this->actingAs($this->student())
            ->post(route('travel.store'), $this->payload([
                'type_of_request' => 'field_visit',
                'other_request_specify' => str_repeat('x', 400),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(TravelDetail::sole()->other_request_specify);
    }

    public function test_the_form_hides_and_disables_it_until_others_is_chosen(): void
    {
        $html = $this->actingAs($this->student())->get(route('travel.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<label for="other_request_specify" data-travel-others\s+hidden\s*>/', $html);
        $this->assertMatchesRegularExpression('/id="other_request_specify"[^>]*hidden disabled/s', $html);
        // The script that shows it, requires it, and empties it when hidden.
        $this->assertStringContainsString("requestType.value === 'others'", $html);
        $this->assertStringContainsString("if (! on) otherSpecify.value = '';", $html);
    }

    public function test_a_draft_with_others_refills_the_field_shown_and_required(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post(route('travel.draft.save'), [
            'type_of_request' => 'others',
            'other_request_specify' => 'Industry visit',
        ])->assertRedirect();

        $html = $this->actingAs($student)
            ->get(route('travel.draft.edit', Application::where('module_type', 'travel')->sole()))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<label for="other_request_specify" data-travel-others\s*>/', $html);
        $this->assertMatchesRegularExpression('/id="other_request_specify"[^>]*value="Industry visit"[^>]*required/s', $html);
    }

    public function test_a_draft_of_another_type_keeps_the_field_hidden(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post(route('travel.draft.save'), ['type_of_request' => 'meeting'])->assertRedirect();

        $html = $this->actingAs($student)
            ->get(route('travel.draft.edit', Application::where('module_type', 'travel')->sole()))
            ->getContent();

        $this->assertMatchesRegularExpression('/id="other_request_specify"[^>]*hidden disabled/s', $html);
    }

    public function test_the_approver_email_shows_the_description_only_when_there_is_one(): void
    {
        $supervisor = $this->supervisor();

        $others = $this->submitted(['type_of_request' => 'others', 'other_request_specify' => 'Industry visit'], $supervisor, '22001001');
        $plain = $this->submitted(['type_of_request' => 'meeting'], $supervisor, '22001002');

        Notification::assertSentTo($supervisor, ApplicationAwaitingAction::class, function ($n) use ($supervisor, $others) {
            $mail = $n->toMail($supervisor);

            return str_contains($mail->subject, "#{$others->id}")
                && in_array('Type of Request: Others (Industry visit)', $mail->introLines, true);
        });
        Notification::assertSentTo($supervisor, ApplicationAwaitingAction::class, function ($n) use ($supervisor, $plain) {
            $mail = $n->toMail($supervisor);

            return str_contains($mail->subject, "#{$plain->id}")
                && in_array('Type of Request: Meeting', $mail->introLines, true);
        });
    }

    public function test_the_application_page_and_pdf_show_it_only_with_a_value(): void
    {
        $supervisor = $this->supervisor();
        $others = $this->submitted(['type_of_request' => 'others', 'other_request_specify' => 'Industry visit'], $supervisor, '22001001');
        $plain = $this->submitted(['type_of_request' => 'meeting'], $supervisor, '22001002');

        $this->actingAs($others->student)->get(route('travel.show', $others))->assertOk()->assertSee('(Industry visit)');
        $page = $this->actingAs($plain->student)->get(route('travel.show', $plain))->assertOk()->getContent();
        $this->assertStringContainsString('Meeting', $page);
        $this->assertDoesNotMatchRegularExpression('/Meeting\s*\(/', $page);

        $this->assertStringContainsString('(Industry visit)', $this->summaryHtml($others));
        $pdf = $this->summaryHtml($plain);
        $this->assertStringContainsString('Meeting', $pdf);
        $this->assertDoesNotMatchRegularExpression('/Meeting\s*\(/', $pdf);
    }

    // ---- Helpers ---------------------------------------------------------------

    protected function submitted(array $overrides, $supervisor, string $matric): Application
    {
        $student = $this->student($matric, "s{$matric}@test.my", ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('travel.store'), $this->payload($overrides))->assertSessionHasNoErrors();

        return Application::where('module_type', 'travel')->where('student_id', $student->id)->sole();
    }

    protected function summaryHtml(Application $application): string
    {
        $application = $application->fresh(['history.approver', 'student', 'documents']);

        return view('norhanis::pack.summary_pdf', [
            'application' => $application,
            'student' => $application->student,
            'module' => $application->module(),
            'stage' => $application->currentStage(),
            'history' => $application->history,
            'documents' => $application->documents,
            'included' => null,
            'travel' => TravelDetail::where('application_id', $application->id)->first(),
            'taPlan' => null,
            'claim' => null,
            'publication' => null,
            'generatedBy' => $application->student,
        ])->render();
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'type_of_request' => 'research_attachment',
            'travel_start_date' => now()->addDays(7)->toDateString(),
            'travel_end_date' => now()->addDays(9)->toDateString(),
            'reason_for_travel' => 'Reservoir simulation workshop',
            'destination_address' => 'Universiti Malaya, Kuala Lumpur',
            'is_international' => 0,
            'contact_person_email' => 'contact@utp.edu.my',
            'supporting_document' => UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'),
            'is_ga_gra' => 'no',
        ];
    }
}
