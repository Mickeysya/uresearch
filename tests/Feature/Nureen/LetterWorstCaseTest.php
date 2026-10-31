<?php

namespace Tests\Feature\Nureen;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\LetterRenderer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nureen\Concerns\KeepsJobsOffTheDevQueue;
use Tests\Feature\Nureen\Concerns\ParsesPdf;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/**
 * Every letter fits on ONE A4 page, even at its longest.
 *
 * Each case fills every form field printed on the letter with exactly its
 * maximum allowed length (config nureen.ga_certification.fields.*.max and
 * purpose_max) at the same time, and every value that comes from a user
 * record -- which no form can limit -- with a long but realistic one (the
 * constants below). The PDF is then parsed and must have exactly one page.
 *
 * Set LETTER_SNAPSHOTS=/some/dir to also write each worst-case PDF there for
 * a visual review; nothing is written otherwise.
 */
class LetterWorstCaseTest extends TestCase
{
    use KeepsJobsOffTheDevQueue;
    use MakesUsers;
    use ParsesPdf;
    use RefreshDatabase;

    /*
     | The longest REALISTIC values for fields that come from user records.
     | Malaysian names with patronymics and honorifics run long ("Nur Aisyah
     | Humaira binti Mohd Zulkarnain"), and academic names carry stacked
     | titles ("Assoc. Prof. Ir. Ts. Dr."); UTP programme names include the
     | field in brackets. Each is comfortably past anything in the seeded
     | data.
     */
    private const STUDENT_NAME = 'Nur Aisyah Humaira binti Mohd Zulkarnain Shah Al-Haddad bin Syed Abdul Rahman Jr';  // 80

    private const MATRIC = '22000000000A';  // 12

    private const PROGRAMME = 'Doctor of Philosophy (Electrical and Electronic Engineering) by Research, Integrated Mode';  // 90

    private const DEPARTMENT = 'Electrical and Electronic Engineering, Faculty of Engineering';  // 61

    private const SUPERVISOR = 'Assoc. Prof. Ir. Ts. Dr. Mohd Faizal Hakimi bin Abdul Rahman Shah Al-Bukhari Jr';  // 80

    private const CHAIR = 'Prof. Ir. Ts. Dr. Siti Nurhaliza binti Tengku Abdul Hamid Al-Sagoff Shahrudin';  // 77

    private const ENDORSER = 'Mohamad Syahmi Ifwat bin Mohamad Jafri Al-Idrus Shahrul Nizam bin Abdul Wahab';  // 78

    private const DIRECTOR = 'Assoc. Prof. Dr. Zulkifly Mohamed Ibrahim bin Tengku Ahmad Shahrudin Al-Attas';  // 78

    private const SNAPSHOT_ENV = 'LETTER_SNAPSHOTS';

    protected function setUp(): void
    {
        parent::setUp();

        LetterRenderer::forgetLogo();
        $this->travelTo(Carbon::parse('2026-11-04 15:42'));
    }

    /** A realistic-looking string of EXACTLY $length characters, ending in a letter. */
    private function ofLength(string $words, int $length): string
    {
        $value = $words;
        while (mb_strlen($value) < $length) {
            $value .= ' '.$words;
        }

        // Never ends in a space, never comes up short.
        $value = mb_substr($value, 0, $length - 1);

        return (str_ends_with($value, ' ') ? mb_substr($value, 0, -1).'y' : $value).'x';
    }

    private function maxOf(string $type, string $field): int
    {
        return config("nureen.ga_certification.fields.{$type}.{$field}.max");
    }

    /** One user per email, so a test can build more than one letter. */
    private function person(string $role, array $attributes): User
    {
        return User::where('email', $attributes['email'])->first() ?? $this->user($role, $attributes);
    }

    private function student(): User
    {
        return $this->person(Role::STUDENT, [
            'name' => self::STUDENT_NAME,
            'email' => 'longest-student@test.my',
            'matric_no' => self::MATRIC,
            'programme' => self::PROGRAMME,
            'department' => self::DEPARTMENT,
        ]);
    }

    private function decided(Application $application, User $by, string $stageKey, string $decision, string $at): void
    {
        $row = ApprovalHistory::create([
            'application_id' => $application->id,
            'approver_id' => $by->id,
            'stage_key' => $stageKey,
            'stage_label' => $stageKey,
            'decision' => $decision,
        ]);
        \Illuminate\Support\Facades\DB::table('approval_history')->where('id', $row->id)->update(['created_at' => $at]);
    }

    /* -----------------------------------------------------------------
     | Fixtures at their longest
     |------------------------------------------------------------------*/

    private function appointment(string $role, string $mode, bool $issued = true): Application
    {
        $student = $this->student();
        $supervisor = $this->person(Role::SUPERVISOR, ['name' => self::SUPERVISOR, 'email' => 'longest-supervisor@test.my']);
        $chair = $this->person(Role::CHAIR, ['name' => self::CHAIR, 'email' => 'longest-chair@test.my', 'department' => self::DEPARTMENT]);
        $cgs = $this->person(Role::NON_EXEC_CGS, ['name' => self::ENDORSER, 'email' => 'longest-cgs@test.my']);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'supervision',
            'status' => $issued ? Application::STATUS_APPROVED : Application::STATUS_PENDING,
            'current_stage' => $mode === 'automatic' ? 'chair' : 'cgs_review',
            'submitted_at' => '2026-10-26 09:00:00',
        ]);
        SupervisionDetail::create([
            'application_id' => $application->id,
            'requested_supervisor_id' => $supervisor->id,
            'justification' => 'Not printed on the letter.',
            'appointment_role' => $role,
            'appointment_type' => 'existing',  // the longer of the two descriptions
            'cgs_stage_snapshot' => $mode,
            'letter_reference' => $issued ? 'CGS/SVAPPT/2026/9999' : null,
            'letter_issued_at' => $issued ? '2026-11-04 15:42:00' : null,
        ]);

        $this->decided($application, $supervisor, 'supervisor', 'endorsed', '2026-10-28 10:00:00');
        $this->decided($application, $chair, 'chair', $mode === 'automatic' ? 'approved' : 'endorsed', '2026-11-02 10:00:00');

        if ($issued && $mode !== 'automatic') {
            $this->decided($application, $cgs, 'cgs_review', 'endorsed', '2026-11-04 15:42:00');
        }

        return $application;
    }

    private function certification(string $type, bool $issued = true): Application
    {
        $student = $this->student();
        $supervisor = $this->person(Role::SUPERVISOR, ['name' => self::SUPERVISOR, 'email' => 'longest-supervisor@test.my']);
        $director = $this->person(Role::SENIOR_DIRECTOR_CGS, ['name' => self::DIRECTOR, 'email' => 'longest-director@test.my']);

        $application = Application::create([
            'student_id' => $student->id,
            'submitted_by_id' => $student->id,
            'module_type' => 'ga_certification',
            'status' => $issued ? Application::STATUS_APPROVED : Application::STATUS_PENDING,
            'current_stage' => 'senior_director',
            'submitted_at' => '2026-10-20 09:00:00',
        ]);

        $fields = [];
        foreach (config("nureen.ga_certification.fields.{$type}") as $key => $field) {
            $fields[$key] = match ($key) {
                // An unbroken code is the hardest thing to wrap.
                'grant_code' => str_repeat('YUTP-FRG015LC0-', 3).'X',
                'course_code' => 'TEB2023-SEM2-2026-AB',
                default => $this->ofLength('Advanced Computational Methods for Reservoir Simulation', $field['max']),
            };
            $fields[$key] = mb_substr($fields[$key], 0, $field['max']);
            $this->assertSame($field['max'], mb_strlen($fields[$key]), "{$key} must be at its maximum");
        }

        $purpose = $this->ofLength('Supporting document for a postgraduate scholarship renewal and visa extension', config('nureen.ga_certification.purpose_max'));

        GaCertificationDetail::create([
            'application_id' => $application->id,
            'supervisor_id' => $supervisor->id,
            'appointment_type' => $type,
            'period_start' => '2026-01-05',
            'period_end' => '2026-12-31',  // "31 December" -- the longest date
            'purpose' => $purpose,
            'reference_number' => $issued ? 'CGS/GACERT/2026/9999' : null,
        ] + $fields);

        $this->decided($application, $supervisor, 'supervisor', 'endorsed', '2026-10-21 10:00:00');
        $this->decided($application, $director, 'cgs_verify', 'reviewed', '2026-10-23 10:00:00');

        if ($issued) {
            $this->decided($application, $director, 'senior_director', 'approved', '2026-11-04 15:42:00');
        }

        return $application;
    }

    private function assertOnePage(string $pdf, string $name): void
    {
        if ($dir = getenv(self::SNAPSHOT_ENV)) {
            @mkdir($dir, 0777, true);
            file_put_contents("{$dir}/{$name}.pdf", $pdf);
        }

        $this->assertSame(1, $this->pdfPageCount($pdf, $name), "{$name} does not fit on one page");
    }

    /* -----------------------------------------------------------------
     | One worst case per letter type
     |------------------------------------------------------------------*/

    public function test_main_supervisor_appointment_at_its_longest_fits_one_page(): void
    {
        $application = $this->appointment(SupervisionDetail::ROLE_MAIN, 'endorse');

        $this->assertOnePage(app(LetterRenderer::class)->appointment($application), 'worst-appointment-main');
    }

    public function test_co_supervisor_appointment_at_its_longest_fits_one_page(): void
    {
        $application = $this->appointment(SupervisionDetail::ROLE_CO, 'endorse');

        $this->assertOnePage(app(LetterRenderer::class)->appointment($application), 'worst-appointment-co');
    }

    public function test_automatic_mode_wording_at_its_longest_fits_one_page(): void
    {
        $application = $this->appointment(SupervisionDetail::ROLE_CO, 'automatic');
        $data = app(LetterRenderer::class)->appointmentData($application);
        $this->assertNull($data['signatory']);

        $this->assertOnePage(app(LetterRenderer::class)->appointment($application), 'worst-appointment-automatic');
    }

    public function test_ga_certification_at_its_longest_fits_one_page(): void
    {
        $application = $this->certification('GA');

        $this->assertOnePage(app(LetterRenderer::class)->certification($application), 'worst-certification-ga');
    }

    public function test_gra_certification_at_its_longest_fits_one_page(): void
    {
        $application = $this->certification('GRA');

        $html = view('nureen::letters.certification', app(LetterRenderer::class)->certificationData($application) + ['qrDataUri' => null])->render();
        foreach (array_keys(config('nureen.ga_certification.fields.GRA')) as $key) {
            // Nothing is truncated to make it fit: every value is printed whole.
            $this->assertStringContainsString(e(GaCertificationDetail::where('application_id', $application->id)->value($key)), $html);
        }

        $this->assertOnePage(app(LetterRenderer::class)->certification($application), 'worst-certification-gra');
    }

    public function test_the_draft_previews_at_their_longest_fit_one_page(): void
    {
        $cgs = $this->user(Role::NON_EXEC_CGS, ['name' => self::ENDORSER, 'email' => 'previewing-cgs@test.my']);
        $appointment = $this->appointment(SupervisionDetail::ROLE_CO, 'endorse', issued: false);
        $this->assertOnePage(app(LetterRenderer::class)->appointment($appointment, draft: true, previewer: $cgs), 'worst-appointment-draft');

        $director = $this->user(Role::SENIOR_DIRECTOR_CGS, ['name' => self::DIRECTOR, 'email' => 'previewing-director@test.my']);
        $certification = $this->certification('GRA', issued: false);
        $this->assertOnePage(app(LetterRenderer::class)->certification($certification, draft: true, previewer: $director), 'worst-certification-draft');
    }

    public function test_the_revoked_versions_at_their_longest_fit_one_page(): void
    {
        $appointment = $this->appointment(SupervisionDetail::ROLE_CO, 'endorse');
        SupervisionDetail::where('application_id', $appointment->id)->update(['letter_revoked_at' => now(), 'letter_revoked_reason' => 'Test.']);
        $this->assertOnePage(app(LetterRenderer::class)->appointment($appointment), 'worst-appointment-revoked');

        $certification = $this->certification('GRA');
        GaCertificationDetail::where('application_id', $certification->id)->update(['revoked_at' => now(), 'revoked_reason' => 'Test.']);
        $this->assertOnePage(app(LetterRenderer::class)->certification($certification), 'worst-certification-revoked');
    }

    /* -----------------------------------------------------------------
     | The limits are enforced where the values come in
     |------------------------------------------------------------------*/

    public function test_validation_refuses_one_character_over_each_limit(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $student = $this->student();
        $student->update(['supervisor_id' => $this->user(Role::SUPERVISOR, ['email' => 'limits-supervisor@test.my'])->id]);

        foreach (['GA', 'GRA'] as $type) {
            $valid = ['appointment_type' => $type, 'period_start' => '2026-01-05', 'period_end' => '2026-06-30'];
            foreach (config("nureen.ga_certification.fields.{$type}") as $key => $field) {
                $valid[$key] = 'x';
            }

            foreach (config("nureen.ga_certification.fields.{$type}") + ['purpose' => ['max' => config('nureen.ga_certification.purpose_max')]] as $key => $field) {
                $this->actingAs($student)
                    ->post(route('ga-certification.store'), [$key => str_repeat('a', $field['max'] + 1)] + $valid)
                    ->assertSessionHasErrors($key);
            }
        }

        // The failed POSTs above flashed old input; start the form clean.
        $this->flushSession();
        $form = $this->actingAs($student)->get(route('ga-certification.create'))->assertOk();
        foreach (config('nureen.ga_certification.fields.GRA') as $key => $field) {
            $form->assertSee('maxlength="'.$field['max'].'"', false)->assertSee("0 / {$field['max']} characters");
        }
        $form->assertSee('0 / '.config('nureen.ga_certification.purpose_max').' characters');
    }
}
