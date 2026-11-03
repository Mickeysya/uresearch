<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyDemoStudentStatus;
use App\Modules\Chloe\Models\CandidacyDismissalImport;
use App\Modules\Chloe\Models\CandidacyDismissalImportRow;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Models\Workstation;
use App\Modules\Chloe\Models\WorkstationLocation;
use App\Modules\Chloe\Models\WorkstationRequest;
use App\Modules\Chloe\Models\WorkstationTerminationNotice;
use App\Modules\Chloe\Notifications\CandidacyDismissed;
use App\Modules\Chloe\Services\CandidacyDismissalUpload as U;
use App\Modules\Chloe\Services\WorkstationAllocator;
use App\Modules\Chloe\Support\CandidacyDismissalList as L;
use App\Modules\Chloe\Support\CandidacyReminderEngine;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Stage C: Dismissal Upload (Registry and CGS): preview classifies, confirm applies in one transaction, effects, access and bad files. */
class CandidacyDismissalUploadTest extends TestCase
{
    use BuildsCandidacyWorld {
        setUp as protected worldSetUp;
    }
    use MakesUsers;
    use RefreshDatabase;

    protected User $registry;

    protected function setUp(): void
    {
        $this->worldSetUp();
        Carbon::setTestNow(Carbon::parse('2027-03-20 09:00:00', 'Asia/Kuala_Lumpur'));
        $this->registry = $this->mkUser(Role::REGISTRY, 'registry@test.my');
        $this->candidacy->update(['candidacy_expiry_date' => '2028-01-01']);
    }

    protected function mkUser(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role).' User', 'email' => $email, 'password' => 'password', 'role' => $role]);
    }

    protected function demo(string $id, string $expiry = '2027-03-01', string $programme = 'PhD in Information Systems (Full-Time)'): DemoStudentProfile
    {
        return DemoStudentProfile::create(['matric_no' => $id, 'name' => "Demo {$id}", 'email' => "d{$id}@utp.edu.my", 'gender' => 'male', 'programme' => $programme, 'programme_start_date' => '2024-01-01', 'candidacy_expiry_date' => $expiry, 'status' => 'active']);
    }

    /** @param list<list<mixed>> $table */
    protected function file(array $table, string $ext = 'xlsx', string $name = 'registry.xlsx'): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($table, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'dis').'.'.$ext;
        ($ext === 'csv' ? new Csv($sheet) : new Xlsx($sheet))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function ids(array $ids, string $heading = 'Student ID'): array
    {
        return array_merge([[$heading, 'Other']], array_map(fn ($i) => [$i, 'x'], $ids));
    }

    protected function preview(UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->registry)->post(route('candidacy.cgs.dismissal-upload.preview'), ['file' => $file]);
    }

    /** A world with one student of every kind. */
    protected function cases(): void
    {
        $this->demo('21040001');                                                                  // will be dismissed
        $soft = $this->demo('21040002');
        CandidacyDemoStudentStatus::create(['demo_profile_id' => $soft->id, 'softbound_submitted_at' => '2027-02-01']);
        $this->demo('21040003', '2027-06-01');                                                     // not expired
        $this->demo('21040004')->update(['status' => 'dismissed']);                                // already dismissed
        $review = $this->demo('21040005');
        CandidacyAppeal::forceCreate(['appeal_no' => 'SCA-21040005-01', 'sequence_no' => 1, 'demo_profile_id' => $review->id, 'status' => 'with_chair', 'round' => 1, 'requested_months' => 3, 'expiry_at_submission' => '2027-03-01', 'reason' => 'x', 'submitted_at' => now()]);
    }

    public function test_preview_classifies_every_case_and_saves_nothing(): void
    {
        $this->cases();
        $before = DemoStudentProfile::pluck('status', 'matric_no')->all();

        $this->preview($this->file($this->ids(['21040001', '21040002', '21040003', '21040004', '21040005', '99999999', '21040001'])))->assertRedirect(route('candidacy.cgs.dismissal-upload'));
        $page = $this->actingAs($this->registry)->get(route('candidacy.cgs.dismissal-upload'))->assertOk();

        foreach (['Will be dismissed', 'Not a current candidate', 'Already dismissed', 'Unknown student ID', 'Duplicate row in file', 'Softbound submitted', 'Appeal in review', 'Candidacy not expired yet'] as $text) {
            $page->assertSee($text);
        }

        $rows = app(U::class)->classify(app(U::class)->parse($this->file($this->ids(['21040001', '21040002', '21040003', '21040004', '21040005', '99999999', '21040001']))));
        $this->assertSame([U::WILL, U::NOT, U::NOT, U::ALREADY, U::NOT, U::UNKNOWN, U::DUPLICATE], array_column($rows, 'status'));
        $this->assertSame(0, CandidacyDismissalImport::count());
        $this->assertSame($before, DemoStudentProfile::pluck('status', 'matric_no')->all());
    }

    public function test_confirm_dismisses_only_valid_rows_records_the_import_and_notifies_immediately(): void
    {
        $this->cases();
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-01']);
        Notification::fake();
        $this->preview($this->file($this->ids(['21040001', '21040002', '21040004', '21040005', '99999999', $this->student->matric_no])));

        $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.confirm'))->assertRedirect(route('candidacy.cgs.dismissal-upload'));

        $this->assertSame('dismissed', DemoStudentProfile::where('matric_no', '21040001')->value('status'));
        $this->assertNotNull(DemoStudentProfile::where('matric_no', '21040001')->value('dismissed_at'));
        $this->assertSame($this->registry->id, DemoStudentProfile::where('matric_no', '21040001')->value('dismissed_by_id'));
        $this->assertSame('active', DemoStudentProfile::where('matric_no', '21040002')->value('status'), 'Softbound submitted: untouched');
        $this->assertSame('dismissed', $this->candidacy->fresh()->status);
        $this->assertNotNull($this->candidacy->fresh()->dismissed_at);

        $import = CandidacyDismissalImport::sole();
        $this->assertSame([6, 2, 4], [$import->rows_total, $import->dismissed, $import->skipped]);
        $this->assertSame('registry.xlsx', $import->file_name);
        $this->assertSame($this->registry->id, $import->uploaded_by);
        $this->assertEqualsCanonicalizing(['21040001', $this->student->matric_no], CandidacyDismissalImportRow::pluck('student_code')->all());

        Notification::assertSentTo($this->student, CandidacyDismissed::class, fn ($n) => $n->delay === null);
        Notification::assertSentOnDemand(CandidacyDismissed::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'd21040001@utp.edu.my');
        Notification::assertSentTimes(CandidacyDismissed::class, 2);
        $this->assertNotContains('21040001', L::candidates()->pluck('studentId')->all(), 'the student left the list');
    }

    public function test_the_dismissal_e_mail_and_bell_carry_the_agreed_wording(): void
    {
        $this->assertStringContainsString('Your candidacy has been ended because the maximum candidacy period was exceeded (Postgraduate Student Handbook section 4.3), as confirmed by the Registry. The Handbook (section 4.4) describes how to appeal for reinstatement; please contact the Registry.', CandidacyDismissed::MESSAGE);
        $n = new CandidacyDismissed('Aina');
        $this->assertStringContainsString(CandidacyDismissed::MESSAGE, implode(' ', $n->toMail($this->student)->introLines));
        $this->assertSame(CandidacyDismissed::MESSAGE, $n->toArray($this->student)['body']);
    }

    public function test_uploading_the_same_file_again_dismisses_nobody_twice(): void
    {
        $this->cases();
        Notification::fake();

        foreach ([1, 2] as $round) {
            $this->preview($this->file($this->ids(['21040001'])));
            $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.confirm'));
        }

        $this->assertSame([1, 0], CandidacyDismissalImport::orderBy('id')->pluck('dismissed')->all());
        $this->assertSame(1, CandidacyDismissalImportRow::count());
        Notification::assertSentOnDemandTimes(CandidacyDismissed::class, 1);
    }

    public function test_a_student_who_stops_being_a_candidate_between_preview_and_confirm_is_skipped_and_reported(): void
    {
        $this->cases();
        Notification::fake();
        $this->preview($this->file($this->ids(['21040001', '21040003'])));
        $this->demo('21040009');
        CandidacyDemoStudentStatus::create(['demo_profile_id' => DemoStudentProfile::where('matric_no', '21040001')->value('id'), 'softbound_submitted_at' => '2027-03-19']);

        $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.confirm'))->assertSessionHas('dismissal_result', fn ($r) => $r['dismissed'] === 0 && $r['changed'] === 1);
        $this->assertSame('active', DemoStudentProfile::where('matric_no', '21040001')->value('status'));
    }

    public function test_one_failure_rolls_the_whole_upload_back(): void
    {
        $this->cases();
        $this->demo('21040010');
        Notification::fake();
        DemoStudentProfile::updating(function ($m) {
            if ($m->matric_no === '21040010') {
                throw new \RuntimeException('boom');
            }
        });
        $this->preview($this->file($this->ids(['21040001', '21040010'])));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.confirm'));
            $this->fail('the error should reach the test');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('active', DemoStudentProfile::where('matric_no', '21040001')->value('status'));
        $this->assertSame(0, CandidacyDismissalImport::count());
        $this->assertSame(0, CandidacyDismissalImportRow::count());
        Notification::assertNothingSent();
    }

    public function test_registry_and_the_cgs_roles_may_use_the_page_nobody_else(): void
    {
        foreach ([Role::REGISTRY, Role::NON_EXEC_CGS, Role::SENIOR_EXEC_CGS, Role::MANAGER_CGS, Role::SENIOR_DIRECTOR_CGS] as $role) {
            $u = User::where('role', $role)->first() ?? $this->mkUser($role, "{$role}@x.my");
            $this->actingAs($u)->get(route('candidacy.cgs.dismissal-upload'))->assertOk();
        }

        foreach ([$this->student, $this->lecturer, $this->chairUser, $this->deanUser] as $u) {
            $this->actingAs($u)->get(route('candidacy.cgs.dismissal-upload'))->assertForbidden();
            $this->actingAs($u)->post(route('candidacy.cgs.dismissal-upload.preview'), ['file' => $this->file($this->ids(['1']))])->assertForbidden();
            $this->actingAs($u)->post(route('candidacy.cgs.dismissal-upload.confirm'))->assertForbidden();
        }

        $this->actingAs($this->registry)->get(route('candidacy.cgs.dismissals'))->assertForbidden();
        $this->actingAs($this->registry)->get(route('candidacy.cgs.operations'))->assertForbidden();
    }

    public function test_the_registry_menu_shows_the_upload_page_only(): void
    {
        $links = collect(app(\App\Modules\Chloe\Workflows\CandidacyAppealWorkflow::class)->links($this->registry))->pluck('route')->all();
        $this->assertSame(['candidacy.cgs.dismissal-upload'], $links);
    }

    public function test_csv_xlsx_and_the_exported_file_all_work_and_the_heading_is_case_insensitive(): void
    {
        $this->cases();
        $upload = app(U::class);

        $this->assertSame('21040001', $upload->parse($this->file($this->ids(['21040001']), 'csv', 'r.csv'))[0]['id']);
        $this->assertSame('21040001', $upload->parse($this->file($this->ids(['21040001'], '  student id '), 'xlsx'))[0]['id']);

        $export = $this->actingAs($this->cgsUser)->post(route('candidacy.cgs.dismissals.export'));
        $roundTrip = new UploadedFile($export->baseResponse->getFile()->getPathname(), 'dismissal-candidates_2027-03-20.xlsx', null, null, true);
        $classified = $upload->classify($upload->parse($roundTrip));
        $this->assertNotEmpty($classified);
        $this->assertSame([U::WILL], array_values(array_unique(array_column($classified, 'status'))), 'the exported file works unchanged');
    }

    public function test_bad_files_are_refused_with_a_clear_message(): void
    {
        $this->preview($this->file([['Name'], ['x']]))->assertSessionHasErrors(['file' => 'The file has no "Student ID" column in its first row.']);

        $txt = tempnam(sys_get_temp_dir(), 'dis').'.txt';
        file_put_contents($txt, "Student ID\n1\n");
        $this->preview(new UploadedFile($txt, 'a.txt', null, null, true))->assertSessionHasErrors(['file' => 'Upload an .xlsx or .csv file.']);

        $junk = tempnam(sys_get_temp_dir(), 'dis').'.xlsx';
        file_put_contents($junk, 'this is not a spreadsheet');
        $this->preview(new UploadedFile($junk, 'a.xlsx', null, null, true))->assertSessionHasErrors('file');

        $many = $this->file($this->ids(array_map(fn ($i) => (string) (30000000 + $i), range(1, 2001))));
        $this->preview($many)->assertSessionHasErrors(['file' => 'The file has more than 2000 rows (2001).']);

        $this->preview($this->file([['Student ID']]))->assertSessionHasErrors('file');
        $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.preview'), [])->assertSessionHasErrors('file');
        $this->assertSame(0, CandidacyDismissalImport::count());
    }

    public function test_confirm_without_a_preview_does_nothing(): void
    {
        $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.confirm'))->assertRedirect(route('candidacy.cgs.dismissal-upload'));
        $this->assertSame(0, CandidacyDismissalImport::count());
    }

    public function test_the_page_lists_the_last_ten_uploads_and_disables_confirm_when_there_is_nothing_to_apply(): void
    {
        $this->cases();
        foreach (range(1, 12) as $i) {
            CandidacyDismissalImport::create(['uploaded_by' => $this->registry->id, 'file_name' => "file-{$i}.xlsx", 'uploaded_at' => now()->subDays(20 - $i), 'rows_total' => $i, 'dismissed' => 0, 'skipped' => $i]);
        }

        $this->preview($this->file($this->ids(['21040003'])));
        $html = $this->actingAs($this->registry)->get(route('candidacy.cgs.dismissal-upload'))->assertOk()->assertSee('file-12.xlsx')->assertSee('file-3.xlsx')->assertDontSee('file-2.xlsx')->assertSee('Confirm is switched off')->getContent();
        $this->assertMatchesRegularExpression('/data-modal-open="wca-confirm-upload"\s+disabled/', $html);
    }

    public function test_open_appeals_are_not_touched_by_a_dismissal(): void
    {
        $appeal = $this->submitted(3);
        $appeal->forceFill(['status' => CandidacyAppeal::STATUS_RETURNED])->save();
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-01']);
        Notification::fake();
        $this->preview($this->file($this->ids([$this->student->matric_no])));
        $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.confirm'));

        $this->assertSame('dismissed', $this->candidacy->fresh()->status);
        $this->assertSame(CandidacyAppeal::STATUS_RETURNED, $appeal->fresh()->status);
    }

    public function test_a_dismissed_student_stops_reminders_and_goes_into_the_workstation_termination_flow(): void
    {
        $this->candidacy->update(['candidacy_expiry_date' => '2027-03-01']);
        $room = WorkstationLocation::create(['block' => 'N2', 'room_code' => 'N2-02-01', 'gender' => 'male', 'name' => 'N2-02-01']);
        $seat = Workstation::create(['workstation_location_id' => $room->id, 'seat_code' => '5', 'position' => 1, 'status' => Workstation::STATUS_OCCUPIED]);
        $request = WorkstationRequest::create(['workstation_id' => $seat->id, 'student_id' => $this->student->id, 'status' => WorkstationRequest::STATUS_CONFIRMED, 'requested_at' => now()->subYear()]);
        Notification::fake();

        $this->preview($this->file($this->ids([$this->student->matric_no])));
        $this->actingAs($this->registry)->post(route('candidacy.cgs.dismissal-upload.confirm'));

        $this->artisan('workstation:refresh-daily')->assertSuccessful();
        $notice = WorkstationTerminationNotice::where('workstation_request_id', $request->id)->sole();
        $this->assertSame(WorkstationAllocator::REASON_TERMINATION_DISMISSAL, $notice->reason);

        $this->candidacy->update(['candidacy_expiry_date' => '2027-12-01']);
        $d = (new CandidacyReminderEngine)->decide(['identity' => \App\Modules\Chloe\Support\StudentDirectory::fromUser($this->student->fresh()), 'candidacy' => $this->candidacy->fresh(), 'demo' => null, 'expiry' => \Carbon\CarbonImmutable::parse('2027-12-01'), 'programme' => $this->student->programme], \Carbon\CarbonImmutable::parse('2027-09-01'));
        $this->assertSame(['stop', 'dismissed'], [$d['action'], $d['reason']]);
    }
}
