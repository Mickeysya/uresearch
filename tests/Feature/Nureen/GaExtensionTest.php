<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Nureen\Models\GaExtensionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * GA Extension — `docs/scope/nureen.md` Module 2.
 *
 * The scope calls document completeness "the point of the module": an
 * extension may not reach CGS without its supporting paperwork and a real
 * justification. That rule lives in one `validate()` call and nothing was
 * guarding it, which is exactly the kind of check that breaks quietly.
 *
 * The chain itself is three fixed stages — Supervisor, CGS Staff, Senior
 * Director — and the middle one is what the legacy app got wrong, so the walk
 * below asserts the position after every decision rather than only the end.
 */
class GaExtensionTest extends TestCase
{
    use MakesUsers;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'current_end_date' => now()->addMonth()->toDateString(),
            'requested_new_end_date' => now()->addMonths(7)->toDateString(),
            'reason_for_extension' => 'Fieldwork at the Gurun plant slipped two semesters '
                .'and the remaining analysis needs the assistantship to continue.',
            'supporting_document' => UploadedFile::fake()->create('supervisor-letter.pdf', 90, 'application/pdf'),
        ];
    }

    public function test_the_supporting_document_is_mandatory(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload(['supporting_document' => null]))
            ->assertSessionHasErrors('supporting_document');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_the_new_end_date_must_be_after_the_current_one(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload([
                'current_end_date' => now()->addMonths(7)->toDateString(),
                'requested_new_end_date' => now()->addMonth()->toDateString(),
            ]))
            ->assertSessionHasErrors('requested_new_end_date');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_a_one_line_reason_is_not_a_justification(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload(['reason_for_extension' => 'Need more time.']))
            ->assertSessionHasErrors('reason_for_extension');

        $this->assertSame(0, Application::where('module_type', 'ga_extension')->count());
    }

    public function test_a_complete_request_is_submitted_with_its_document_on_the_private_disk(): void
    {
        Storage::fake('local');

        $this->actingAs($this->student())
            ->post(route('ga-extension.store'), $this->payload())
            ->assertRedirect(route('applications.index'));

        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('supervisor', $application->current_stage);

        $detail = GaExtensionDetail::where('application_id', $application->id)->sole();
        $this->assertTrue($detail->requested_new_end_date->greaterThan($detail->current_end_date));

        $document = $application->documents()->sole();
        $this->assertSame('supervisor-letter.pdf', $document->original_name);
        $this->assertStringStartsWith("applications/{$application->id}/", $document->path);
        $this->assertStringNotContainsString('supervisor-letter', $document->path);
    }

    public function test_the_chain_needs_all_three_approvals(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());

        $application = Application::where('module_type', 'ga_extension')->sole();

        // 1. Supervisor endorses -- this is the step the legacy app treated as
        //    the end of the chain.
        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('cgs_verify', $application->current_stage);

        // 2. CGS verifies.
        $this->actingAs($this->cgs())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $application->refresh();
        $this->assertSame(Application::STATUS_PENDING, $application->status);
        $this->assertSame('senior_director', $application->current_stage);

        // 3. Only the Senior Director can finish it.
        $this->actingAs($this->seniorDirector())
            ->post(route('ga-extension.decide', $application), ['decision' => 'approve']);

        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_a_rejection_anywhere_in_the_chain_closes_it(): void
    {
        Storage::fake('local');

        $supervisor = $this->supervisor();
        $student = $this->student(attributes: ['supervisor_id' => $supervisor->id]);

        $this->actingAs($student)->post(route('ga-extension.store'), $this->payload());

        $application = Application::where('module_type', 'ga_extension')->sole();

        $this->actingAs($supervisor)
            ->post(route('ga-extension.decide', $application), [
                'decision' => 'reject',
                'remarks' => 'The department cannot fund a further seven months.',
            ]);

        $application->refresh();
        $this->assertSame(Application::STATUS_REJECTED, $application->status);
        // The stage it died on is held, so the trail says who stopped it.
        $this->assertSame('supervisor', $application->current_stage);
    }
}
