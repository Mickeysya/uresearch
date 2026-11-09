<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDepartment;
use App\Modules\Chloe\Models\CandidacyStaffAssignment;
use App\Modules\Chloe\Models\CandidacyStaffProfile;
use App\Modules\Chloe\Models\StudyCandidacy;
use App\Modules\Chloe\Notifications\CandidacyAppealNotice;
use App\Modules\Chloe\Support\CandidacyDepartmentResolver as R;
use App\Modules\Chloe\Support\CandidacyStaffDirectory;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Departments, department auto-fill, the read-only department on the form, the escaping of "&", the portal title and My Signature. */
class CandidacyDepartmentRosterTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    // ---- departments and auto-fill -----------------------------------------------------------------------------

    public function test_the_nine_departments_and_their_faculties_are_configured(): void
    {
        $d = config('candidacy.departments');

        $this->assertCount(9, $d);
        $this->assertSame(['Chemical Engineering', 'Civil & Environmental Engineering', 'Electrical & Electronic Engineering', 'Mechanical Engineering', 'Petroleum Engineering', 'Geoscience'], array_values(array_map(fn ($x) => $x[0], array_filter($d, fn ($x) => $x[1] === 'Faculty of Engineering'))));
        $this->assertSame(['Fundamental & Applied Sciences', 'Computer & Information Sciences', 'Management & Humanities'], array_values(array_map(fn ($x) => $x[0], array_filter($d, fn ($x) => $x[1] === 'Faculty of Science, Management & Computing'))));
        $this->assertNotContains('Geosciences & Petroleum Engineering', array_column($d, 0));
    }

    public function test_the_keyword_mapping(): void
    {
        $cases = [
            'PhD Chemical Engineering (Full-Time)' => 'CHE', 'MSc Civil Engineering' => 'CEE', 'MSc Environmental Engineering' => 'CEE', 'PhD Electrical & Electronic Engineering' => 'EEE',
            'MSc Mechanical Engineering' => 'MEC', 'MSc Petroleum Engineering' => 'PET', 'PhD Geoscience' => 'GEO', 'MSc Geophysics' => 'GEO',
            'MSc Computer Science' => 'CIS', 'MSc Information Technology' => 'CIS', 'MSc IT' => 'CIS', 'PhD Software Engineering' => 'CIS',
            'MSc Applied Science' => 'APS', 'PhD Chemistry' => 'APS', 'MSc Physics' => 'APS', 'PhD Mathematics' => 'APS',
            'MBA' => 'MHU', 'PhD Management' => 'MHU', 'MSc Humanities' => 'MHU',
        ];

        foreach ($cases as $text => $code) {
            $this->assertSame($code, R::codeFor($text, '1'), $text);
        }
    }

    public function test_text_that_maps_to_nothing_is_placed_by_a_stable_hash(): void
    {
        $codes = array_keys(config('candidacy.departments'));

        foreach (['22001001', '22001002', '26005007', 'x'] as $id) {
            $first = R::codeFor('Basket weaving', $id);
            $this->assertSame($first, R::codeFor('Basket weaving', $id), 'stable');
            $this->assertContains($first, $codes);
        }
        $this->assertSame(R::codeFor(null, '22001001'), R::codeFor('', '22001001'));
    }

    public function test_the_live_demo_accounts_are_computer_and_information_sciences_whatever_their_programme(): void
    {
        $a = $this->student('26005008', 'chloe_26005007@utp.edu.my', ['programme' => 'PhD Chemical Engineering']);
        $b = $this->student('22001055', 'student@utp.edu.my', ['programme' => 'MSc Petroleum Engineering']);
        $c = $this->student('22009991', 'other@test.my', ['programme' => 'PhD Chemical Engineering']);

        $this->assertSame('CIS', R::codeForUser($a));
        $this->assertSame('CIS', R::codeForUser($b));
        $this->assertSame('CHE', R::codeForUser($c));
    }

    public function test_an_empty_department_is_filled_from_the_programme_and_stored_on_the_record(): void
    {
        CandidacyDepartment::create(['code' => 'CHE', 'name' => 'Chemical Engineering', 'faculty' => 'Faculty of Engineering']);
        $student = $this->student('22009992', 'chem@test.my', ['programme' => 'PhD Chemical Engineering']);
        $candidacy = StudyCandidacy::create(['student_id' => $student->id, 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active']);

        $this->assertNull($candidacy->department_id);
        $this->assertSame('CHE', R::forCandidacy($candidacy->fresh())->code);
        $this->assertSame('CHE', CandidacyDepartment::find($candidacy->fresh()->department_id)->code);
    }

    // ---- the form: read-only department, supervisors of that department only ----------------------------------

    protected function otherDepartment(): array
    {
        $dept = CandidacyDepartment::create(['code' => 'EEE', 'name' => 'Electrical & Electronic Engineering', 'faculty' => 'Faculty of Engineering']);
        $mk = function (string $name, string $role) use ($dept) {
            $p = CandidacyStaffProfile::create(['full_name' => $name, 'designation' => $role, 'department_id' => $dept->id]);
            CandidacyStaffAssignment::create(['department_id' => $dept->id, 'staff_profile_id' => $p->id, 'role' => $role]);

            return $p;
        };

        return [$dept, $mk('Dr. Elsewhere Lecturer', 'lecturer'), $mk('Prof. Dr. Elsewhere Chair', 'chair')];
    }

    public function test_step_one_shows_the_recorded_supervisor_programme_manager_chair_and_dean_read_only(): void
    {
        [, $elsewhere] = $this->otherDepartment();
        $this->student->update(['programme' => 'PhD in Information Systems (Full-Time)']);

        $html = $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 1))->assertOk()
            ->assertSee('Computer & Information Sciences')->assertSee('PhD in Information Systems (Full-Time)')->assertSee('Supervisor / Programme Manager')->getContent();

        $this->assertSingleEscaped($html, 'new appeal step 1');
        $this->assertStringNotContainsString('<select id="wca-supervisor"', $html, 'the student no longer chooses');
        $this->assertStringContainsString('id="wca-supervisor" class="wca-input" type="text" value="'.$this->profile($this->lecturer)->full_name.'" readonly', $html);
        $this->assertStringContainsString('value="'.$this->profile($this->chairUser)->full_name.'" readonly', $html);
        $this->assertStringContainsString('value="'.$this->profile($this->deanUser)->full_name.'" readonly', $html);
        $this->assertStringNotContainsString($elsewhere->full_name, $html);
    }

    public function test_a_posted_supervisor_is_ignored_and_a_tampered_department_is_refused(): void
    {
        [$other, $elsewhere] = $this->otherDepartment();
        $post = fn (array $data) => $this->actingAs($this->student)->post(route('candidacy.appeals.draft.save', 1), ['intent' => 'next'] + $data);
        $own = $this->profile($this->lecturer)->id;

        $post(['department_id' => $other->id])->assertSessionHasErrors('department_id');

        // a posted supervisor (a tampered form) changes nothing: the person on record is used
        $post(['supervisor_profile_id' => $elsewhere->id])->assertSessionHasNoErrors();
        $this->assertSame($own, CandidacyAppeal::where('student_id', $this->student->id)->value('supervisor_profile_id'));
    }

    public function test_an_appeal_is_always_routed_to_the_person_on_record_even_if_the_draft_was_tampered_with(): void
    {
        [, $elsewhere] = $this->otherDepartment();
        $draft = $this->draft();
        $draft->update(['supervisor_profile_id' => $elsewhere->id]);

        $appeal = $this->service->submit($this->student, $draft->fresh());

        $this->assertSame($this->profile($this->lecturer)->id, $appeal->supervisor_profile_id);
    }

    public function test_a_student_without_a_supervisor_programme_manager_on_record_cannot_start_an_appeal(): void
    {
        \App\Modules\Chloe\Models\CandidacyStudentSupervisor::where('student_id', $this->student->id)->delete();
        $message = 'Your Supervisor / Programme Manager is not recorded yet. Please contact CGS.';

        $this->actingAs($this->student)->get(route('candidacy.status'))->assertOk()->assertSee($message);
        $this->actingAs($this->student)->get(route('candidacy.appeals.new'))->assertOk()->assertSee($message);
        $this->actingAs($this->student)->get(route('candidacy.appeals.draft', 1))->assertRedirect(route('candidacy.appeals.new'));
        $this->assertSame(0, CandidacyAppeal::where('student_id', $this->student->id)->count());
        $this->assertContains($message, $this->service->eligibilityProblems($this->candidacy->fresh()));
    }

    public function test_an_appeal_already_submitted_keeps_its_person_when_the_record_changes_later(): void
    {
        $appeal = $this->submitted();
        $routedTo = $appeal->supervisor_profile_id;
        [, $elsewhere] = $this->otherDepartment();

        \App\Modules\Chloe\Models\CandidacyStudentSupervisor::where('student_id', $this->student->id)->update(['staff_profile_id' => $elsewhere->id]);

        $this->assertSame($routedTo, $appeal->fresh()->supervisor_profile_id);
    }

    public function test_the_backfill_gives_each_student_a_lecturer_of_their_own_department_and_only_adds(): void
    {
        $dept = $this->candidacy->department;
        [$otherDept] = $this->otherDepartment();
        $second = $this->student('22009991', 'second@test.my', ['programme' => 'PhD in Information Systems (Full-Time)']);
        StudyCandidacy::create(['student_id' => $second->id, 'department_id' => $dept->id, 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active']);
        $demo = \App\Modules\Chloe\Models\DemoStudentProfile::create(['matric_no' => '99000001', 'name' => 'Demo One', 'email' => 'demo1@x.my', 'gender' => 'male', 'programme' => 'MSc in Data Science (Coursework)', 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active']);

        $this->artisan('candidacy:backfill-supervisors --dry-run')->assertSuccessful();
        $this->assertNull(CandidacyStaffDirectory::supervisorForStudent($second->id), 'a dry run writes nothing');

        $this->artisan('candidacy:backfill-supervisors')->assertSuccessful();

        $this->assertSame($this->profile($this->lecturer)->id, CandidacyStaffDirectory::supervisorForStudent($second->id)->id, 'a lecturer of the student\'s own department (here the only one)');
        $this->assertSame($this->profile($this->lecturer)->id, CandidacyStaffDirectory::supervisorForStudent($this->student->id)->id, 'the existing row is left alone');
        $this->assertSame(\App\Modules\Chloe\Models\CandidacyStudentSupervisor::KIND_PROGRAMME_MANAGER, \App\Modules\Chloe\Models\CandidacyStudentSupervisor::where('demo_profile_id', $demo->id)->value('kind'));
        $count = \App\Modules\Chloe\Models\CandidacyStudentSupervisor::count();

        $this->artisan('candidacy:backfill-supervisors')->assertSuccessful();
        $this->assertSame($count, \App\Modules\Chloe\Models\CandidacyStudentSupervisor::count(), 'a second run adds nothing');
    }

    public function test_a_student_without_a_department_is_told_so_and_cannot_submit(): void
    {
        $student = $this->student('22009993', 'nodept@test.my', ['programme' => 'PhD Chemical Engineering']); // CHE does not exist in this fixture
        StudyCandidacy::create(['student_id' => $student->id, 'programme_start_date' => '2025-01-01', 'candidacy_expiry_date' => '2027-06-01', 'status' => 'active']);
        $this->recordSupervisor($student, $this->profile($this->lecturer));

        $this->actingAs($student)->get(route('candidacy.appeals.draft', 1))->assertOk()->assertSee('Your department could not be found in your student record');
        $this->actingAs($student)->post(route('candidacy.appeals.draft.save', 1), ['intent' => 'next'])->assertSessionHasErrors('department');
    }

    public function test_the_dean_is_one_person_for_every_department(): void
    {
        [$other] = $this->otherDepartment();
        $appeal = $this->submitted();

        $this->assertSame($this->profile($this->deanUser)->id, CandidacyStaffDirectory::dean()->id);
        $this->assertSame(CandidacyStaffDirectory::dean($other)->id, CandidacyStaffDirectory::dean($this->candidacy->department)->id);
        $this->assertSame($this->profile($this->deanUser)->id, $appeal->dean_profile_id);
    }

    // ---- "&" is escaped exactly once ---------------------------------------------------------------------------

    /** The text a person reads: tags removed, entities decoded once. A double-escaped name would still read "Computer &amp; ...". */
    protected function visible(string $html): string
    {
        // a read-only input shows its value as text, so its value attribute counts as what a person reads
        $html = preg_replace('~<input\b[^>]*\bvalue="([^"]*)"[^>]*>~', ' $1 ', preg_replace('~<(script|style)\b.*?</\1>~s', '', $html));

        return html_entity_decode(strip_tags($html));
    }

    protected function assertSingleEscaped(string $html, string $where): void
    {
        $this->assertStringNotContainsString('&amp;amp;', $html, $where);
        $text = $this->visible($html);
        $this->assertStringContainsString('Computer & Information Sciences', $text, "{$where}: ".substr(preg_replace('/\s+/', ' ', $text), 0, 300));
        $this->assertStringNotContainsString('&amp;', $text, "double-escaped & on {$where}");
    }

    public function test_a_name_with_an_ampersand_is_shown_correctly_on_every_page(): void
    {
        $appeal = $this->toStage('chair');
        $this->assertSingleEscaped($this->actingAs($this->student)->get(route('candidacy.appeals.show', $appeal))->getContent(), 'appeal page (student)');
        $this->assertSingleEscaped($this->actingAs($this->chairUser)->get(route('candidacy.appeals.show', $appeal))->getContent(), 'appeal page (chair)');
        $this->assertStringNotContainsString('&amp;amp;', $this->actingAs($this->chairUser)->get(route('candidacy.appeals.region', $appeal))->getContent());
        $this->assertSingleEscaped($this->actingAs($this->chairUser)->get(route('candidacy.portal.queue'))->getContent(), 'portal table and filter');
        $this->assertSingleEscaped($this->actingAs($this->chairUser)->get(route('candidacy.portal.region'))->getContent(), 'portal region');
        $this->assertSingleEscaped($this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations'))->getContent(), 'operations');
        $this->assertSingleEscaped($this->actingAs($this->cgsUser)->get(route('candidacy.cgs.operations.region'))->getContent(), 'operations region');
        $this->assertStringContainsString('<optgroup label="Faculty of Science, Management &amp; Computing">', $this->actingAs($this->chairUser)->get(route('candidacy.portal.queue'))->getContent()); // once-escaped attribute
    }

    public function test_a_name_with_an_ampersand_is_shown_correctly_in_the_assistant_widget_e_mail_and_bell(): void
    {
        $html = $this->actingAs($this->student)->get(route('candidacy.status'))->getContent();
        $this->assertStringContainsString('data-domain="candidacy"', $html); // the live widget shows no department; the page must simply stay clean
        $this->assertStringNotContainsString('&amp;amp;', $html);

        $appeal = $this->submitted();
        $notice = new CandidacyAppealNotice('turn', ['department' => $appeal->fresh()->department->name, 'appeal_no' => $appeal->appeal_no, 'student_name' => 'A & B', 'stage' => 'Supervisor', 'requested_months' => 4, 'link' => 'http://x/y']);

        $this->assertSingleEscaped((string) $notice->toMail($this->lecturer)->render(), 'e-mail');
        $this->assertStringContainsString('Computer & Information Sciences', $notice->toArray($this->lecturer)['title']);
        $this->assertStringNotContainsString('&amp;', $notice->toArray($this->lecturer)['title'], 'bell');
    }

    public function test_a_name_with_an_ampersand_is_shown_correctly_in_the_memo(): void
    {
        $gs = trim((string) shell_exec('command -v gs'));
        if ($gs === '') {
            $this->markTestSkipped('Ghostscript is not installed here.');
        }
        $appeal = $this->service->decide($this->deanUser, $this->toStage('dean', 2), 'approve');
        $file = tempnam(sys_get_temp_dir(), 'memo');
        file_put_contents($file, Storage::disk('local')->get($appeal->memo_path));
        $text = preg_replace('/\s+/', ' ', (string) shell_exec(escapeshellarg($gs).' -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -sOutputFile=- '.escapeshellarg($file).' 2>/dev/null'));
        @unlink($file);

        $this->assertStringContainsString('Computer & Information Sciences', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    // ---- the portal title ---------------------------------------------------------------------------------------

    public function test_the_portal_is_called_study_candidacy_approval_portal(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('candidacy.portal.queue'))->getContent();

        $this->assertMatchesRegularExpression('~<h2>\s*Study Candidacy Approval Portal\s*</h2>~', $html);
        // the shared layout writes a fixed <title>, so our own script sets the tab title from this attribute
        $this->assertStringContainsString('data-doc-title="Study Candidacy Approval Portal"', $html);
        $this->assertStringContainsString('document.title', file_get_contents(public_path('js/chloe-candidacy.js')));
    }

    // ---- ONE My Signature page ----------------------------------------------------------------------------------

    public function test_there_is_exactly_one_signature_page_and_one_label(): void
    {
        $pages = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_contains($r->uri(), 'signature') && ! str_contains($r->uri(), 'image') && str_contains($r->getName() ?? '', 'candidacy'));
        $this->assertCount(1, $pages);
        $this->assertSame('candidacy.signature.show', $pages->first()->getName());

        // One entry point for every signature a person keeps: Core's "My Signatures",
        // which shows this module's signature as a section of its own.
        $labels = array_column(app(\App\Modules\Core\Services\ModuleRegistry::class)->linksFor($this->lecturer), 'label');
        $this->assertContains('My Signatures', $labels);
        $this->assertNotContains('My Signature', $labels);
        $this->assertNotContains('My Appeal Signature', $labels);
    }

    public function test_the_portal_button_and_the_appeal_link_open_the_same_page_with_a_return_path(): void
    {
        $appeal = $this->submitted();
        $portal = html_entity_decode($this->actingAs($this->lecturer)->get(route('candidacy.portal.queue', ['q' => 'SCA', 'tab' => 'ongoing']))->getContent());
        $this->assertMatchesRegularExpression('~href="[^"]*/candidacy/signature\?return=%2Fcandidacy%2Fportal%3Ftab%3Dongoing%26q%3DSCA"[^>]*><button[^>]*>My Signature~', $portal);

        $this->service_signatures()->remove($this->profile($this->lecturer));
        $page = html_entity_decode($this->actingAs($this->lecturer)->get(route('candidacy.appeals.show', ['appeal' => $appeal->id, 'return' => '/candidacy/portal?tab=pending&page=2']))->getContent());
        $this->assertStringContainsString('Upload your signature', $page);
        preg_match('~href="([^"]*/candidacy/signature\?return=[^"]*)"[^>]*>Upload your signature~', $page, $m);
        parse_str(parse_url($m[1], PHP_URL_QUERY), $q);
        $this->assertSame('/candidacy/appeals/'.$appeal->id.'?return='.urlencode('/candidacy/portal?tab=pending&page=2'), $q['return']);
        $this->assertNotNull(\App\Modules\Chloe\Support\CandidacyReturnPath::validate($q['return']));
    }

    public function test_after_an_upload_the_user_is_sent_back_and_an_outside_return_path_is_ignored(): void
    {
        $back = '/candidacy/portal?tab=ongoing&q=SCA&page=2';
        $up = fn (?string $return) => $this->actingAs($this->lecturer)->post(route('candidacy.signature.store'), array_filter(['signature' => UploadedFile::fake()->image('s.png', 800, 300), 'return' => $return]));

        $up($back)->assertRedirect($back);
        $up(null)->assertRedirect(route('candidacy.signature.show', [], false));
        foreach (['https://evil.example/', '//evil.example/x', '/admin', 'javascript:alert(1)'] as $bad) {
            $up($bad)->assertRedirect(route('candidacy.signature.show', [], false));
        }
        $this->actingAs($this->lecturer)->delete(route('candidacy.signature.destroy'), ['return' => $back])->assertRedirect($back);
    }

    public function test_the_signature_page_carries_the_return_path_in_its_forms_and_back_link(): void
    {
        $back = '/candidacy/portal?tab=history';

        // The old page forwards to Core's "My Signatures" with the return path intact...
        $this->actingAs($this->lecturer)->get(route('candidacy.signature.show', ['return' => $back]))
            ->assertRedirect(route('signatures.index', ['return' => $back]).'#candidacy');

        // ...which puts it in the upload forms and the Back link.
        $html = html_entity_decode($this->actingAs($this->lecturer)->get(route('signatures.index', ['return' => $back]))->getContent());
        $this->assertStringContainsString('name="return" value="'.$back.'"', $html);
        $this->assertMatchesRegularExpression('~<a href="'.preg_quote($back, '~').'" class="page-header-back~', $html);

        // An outside address is dropped at both steps.
        $this->actingAs($this->lecturer)->get(route('candidacy.signature.show', ['return' => 'https://evil.example/']))
            ->assertRedirect(route('signatures.index').'#candidacy');
        $evil = html_entity_decode($this->actingAs($this->lecturer)->get(route('signatures.index', ['return' => 'https://evil.example/']))->getContent());
        $this->assertStringNotContainsString('evil.example', $evil);
    }
}
