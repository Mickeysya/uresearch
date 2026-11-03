<?php

namespace Tests\Feature\Hani;

use Tests\Feature\Hani\Concerns\KeepsJobsOffTheDevQueue;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Hani\Models\VivaEligibility;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Notifications\VivaAwaitingAcademicExec;
use App\Modules\Hani\Notifications\VivaSoftboundReturned;
use App\Modules\Hani\Notifications\VivaSoftboundSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Viva step 2: the softbound thesis (Form #019). Its own application, filed
 * only once the notice is acknowledged; a return closes that attempt and
 * leaves the viva open.
 */
class VivaSoftboundTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use RefreshDatabase;

    protected User $student;

    protected User $ae;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->student = $this->student(attributes: ['programme' => 'MSc Full-Time', 'department' => 'Computing']);
        $this->ae = $this->academicExec();
        $this->ae->update(['department' => 'Computing']);
    }

    protected function fileNotice(): Application
    {
        VivaEligibility::updateOrCreate(['student_id' => $this->student->id], array_fill_keys([
            'research_methodology_passed', 'publication_accepted', 'registered_current_semester',
            'no_outstanding_fees', 'within_candidacy_period',
        ], true));

        $this->actingAs($this->student)->post(route('viva.notice.store'), [
            'thesis_title' => 'Constraint-based examiner matching',
            'expected_softbound_date' => now()->addMonths(4)->toDateString(),
            'publication_proof' => UploadedFile::fake()->create('accepted.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        return Application::where('module_type', 'viva_voce')->sole();
    }

    protected function acknowledge(Application $notice): void
    {
        $this->actingAs($this->ae)->post(route('viva.decide', $notice), ['decision' => 'approve']);
    }

    protected function softbound(array $overrides = []): array
    {
        $pdf = fn (string $name, int $kb = 100) => UploadedFile::fake()->create($name, $kb, 'application/pdf');

        return $overrides + [
            'field_of_study' => 'Data analytics',
            'contact_number' => '013-337 2001',
            'personal_email' => 'ahmad@gmail.com',
            'session' => 'September',
            'year' => now()->year,
            'thesis_title' => 'Constraint-based examiner matching',
            'similarity_percent' => 18.5,
            'word_count' => 42000,
            'co_supervisor_email' => 'co.sv@utp.edu.my',
            'declaration' => 1,
            'format_confirmation' => $pdf('format.pdf'),
            'thesis' => $pdf('thesis.pdf', 4000),
            'turnitin_report' => $pdf('turnitin.pdf', 300),
            'rcs_correction_confirmation' => $pdf('rcs-confirmation.pdf'),
            'rcs_correction_summary' => $pdf('rcs-summary.pdf'),
        ];
    }

    public function test_softbound_waits_for_an_acknowledged_notice(): void
    {
        $this->actingAs($this->student)->get(route('viva.softbound.create'))
            ->assertRedirect(route('viva.eligibility'));

        $notice = $this->fileNotice();

        // Filed but not yet acknowledged.
        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->softbound())
            ->assertRedirect(route('viva.eligibility'));
        $this->assertSame(0, VivaSoftboundDetail::count());

        $this->acknowledge($notice);

        // The tracker follows stored status: the notice is done, the softbound is next.
        $this->actingAs($this->student)->get(route('viva.eligibility'))
            ->assertSee('Upload your softbound thesis (Form #019, PDF) to continue.');

        $this->actingAs($this->student)->get(route('viva.softbound.create'))
            ->assertOk()
            ->assertSee('50,000 words or fewer')
            ->assertSee('Thesis Format Confirmation Form')
            ->assertDontSee('RCS Correction Summary</label>', false);
    }

    public function test_form_019_limits_are_enforced(): void
    {
        $this->acknowledge($this->fileNotice());

        foreach ([
            'similarity_percent' => ['similarity_percent' => 25.01],
            'word_count' => ['word_count' => 50001],
            'declaration' => ['declaration' => null],
            'session' => ['session' => 'Semester 1'],
            'co_supervisor_email' => ['co_supervisor_email' => 'co.sv@gmail.com'],
            'format_confirmation' => ['format_confirmation' => null],
            'thesis' => ['thesis' => UploadedFile::fake()->create('thesis.docx', 100)],
        ] as $field => $override) {
            $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->softbound($override))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, VivaSoftboundDetail::count());
    }

    public function test_a_return_closes_the_attempt_and_the_student_files_again(): void
    {
        $notice = $this->fileNotice();
        $this->acknowledge($notice);

        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->softbound())
            ->assertSessionHasNoErrors();

        $first = Application::where('module_type', 'viva_softbound')->sole();
        $this->assertSame('softbound_check', $first->current_stage);
        $this->assertSame($notice->id, VivaSoftboundDetail::sole()->viva_application_id);
        // MSc: three uploads; the two RCS forms are PhD only and are ignored.
        $this->assertSame(3, $first->documents()->count());
        $this->assertSame('September', VivaSoftboundDetail::sole()->session);
        Notification::assertSentTo($this->student, VivaSoftboundSubmitted::class);

        // One attempt at a time.
        $this->actingAs($this->student)->get(route('viva.softbound.create'))
            ->assertRedirect(route('viva.eligibility'));

        // The AE sees it, with the early-filing flag: it is days, not months, after the notice.
        $this->actingAs($this->ae)->get(route('viva.softbound.queue'))
            ->assertOk()
            ->assertSee('18.50%')
            ->assertSee('September '.now()->year)
            ->assertSee('The guideline asks for at least')
            ->assertSee('Not started');

        // A return needs a reason.
        $this->actingAs($this->ae)->post(route('viva.softbound.decide', $first), ['decision' => 'reject'])
            ->assertSessionHas('error');
        $this->assertSame(Application::STATUS_PENDING, $first->fresh()->status);

        $this->actingAs($this->ae)->post(route('viva.softbound.decide', $first), [
            'decision' => 'reject',
            'remarks' => 'Abstract in Bahasa Melayu is missing.',
        ]);
        $this->assertSame(Application::STATUS_REJECTED, $first->fresh()->status);
        $this->assertSame(Application::STATUS_APPROVED, $notice->fresh()->status);
        Notification::assertSentTo($this->student, VivaSoftboundReturned::class);

        $this->actingAs($this->student)->get(route('viva.eligibility'))
            ->assertOk()
            ->assertSee('Abstract in Bahasa Melayu is missing.')
            ->assertSee('Resubmit Softbound Thesis');

        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->softbound())
            ->assertSessionHasNoErrors();
        $second = Application::where('module_type', 'viva_softbound')->latest('id')->first();

        $this->actingAs($this->ae)->post(route('viva.softbound.decide', $second), ['decision' => 'approve']);
        $this->assertSame(Application::STATUS_APPROVED, $second->fresh()->status);

        // Verified: nothing more to file.
        $this->actingAs($this->student)->get(route('viva.softbound.create'))
            ->assertRedirect(route('viva.eligibility'));
    }

    public function test_the_students_own_academic_executive_is_told_about_new_work(): void
    {
        $elsewhere = $this->user('academic_exec', ['email' => 'ae2@test.my', 'department' => 'Chemical Engineering']);

        $notice = $this->fileNotice();
        Notification::assertSentTo($this->ae, VivaAwaitingAcademicExec::class,
            fn ($n) => str_contains($n->toArray($this->ae)['title'], 'New Notice of Thesis Submission from Ahmad Danial'));

        $this->acknowledge($notice);
        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->softbound());
        $first = Application::where('module_type', 'viva_softbound')->sole();
        Notification::assertSentTo($this->ae, VivaAwaitingAcademicExec::class,
            fn ($n) => str_contains($n->toArray($this->ae)['title'], 'New softbound thesis'));

        $this->actingAs($this->ae)->post(route('viva.softbound.decide', $first), ['decision' => 'reject', 'remarks' => 'Fix the abstract.']);
        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->softbound());
        Notification::assertSentTo($this->ae, VivaAwaitingAcademicExec::class,
            fn ($n) => str_contains($n->toArray($this->ae)['title'], 'Resubmitted softbound thesis'));

        Notification::assertNotSentTo($elsewhere, VivaAwaitingAcademicExec::class);
    }

    public function test_phd_word_limit_is_one_hundred_thousand(): void
    {
        $this->student->update(['programme' => 'PhD Full-Time']);
        VivaEligibility::create(['student_id' => $this->student->id, 'rcs_passed' => true]);
        $this->acknowledge($this->fileNotice());

        // The RCS forms are required at PhD level.
        $this->actingAs($this->student)->post(route('viva.softbound.store'),
            $this->softbound(['word_count' => 100000, 'rcs_correction_summary' => null]))
            ->assertSessionHasErrors('rcs_correction_summary');

        $this->actingAs($this->student)->post(route('viva.softbound.store'), $this->softbound(['word_count' => 100000]))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, Application::where('module_type', 'viva_softbound')->sole()->documents()->count());
    }
}
