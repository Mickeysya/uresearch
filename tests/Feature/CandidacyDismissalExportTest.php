<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyDismissalExport;
use App\Modules\Chloe\Models\DemoStudentProfile;
use App\Modules\Chloe\Support\CandidacyDismissalList as L;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Stage B: the Excel export of the Dismissal List. */
class CandidacyDismissalExportTest extends TestCase
{
    use BuildsCandidacyWorld {
        setUp as protected worldSetUp;
    }
    use MakesUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->worldSetUp();
        Carbon::setTestNow(Carbon::parse('2027-03-20 09:00:00', 'Asia/Kuala_Lumpur'));
        $this->candidacy->update(['candidacy_expiry_date' => '2028-01-01']);

        foreach ([['21030001', '2027-03-01', 'PhD in Chemical Engineering (Full-Time)'], ['21030002', '2027-02-01', 'Master of Science in Civil Engineering (Full-Time)'], ['21030003', '2027-01-15', 'PhD in Civil Engineering (Full-Time)']] as [$id, $exp, $prog]) {
            DemoStudentProfile::create(['matric_no' => $id, 'name' => "Demo {$id}", 'email' => "d{$id}@utp.edu.my", 'gender' => 'male', 'programme' => $prog, 'programme_start_date' => '2024-01-01', 'candidacy_expiry_date' => $exp, 'status' => 'active']);
        }
    }

    /** @return array<int, array<int, mixed>> the sheet's rows */
    protected function sheet($response): array
    {
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();

        return $sheet->toArray(null, true, false, false);
    }

    public function test_the_file_has_the_agreed_name_columns_and_rows_with_text_ids(): void
    {
        $response = $this->actingAs($this->cgsUser)->post(route('candidacy.cgs.dismissals.export'));

        $this->assertSame('attachment; filename=dismissal-candidates_2027-03-20.xlsx', $response->headers->get('Content-Disposition'));
        $rows = $this->sheet($response);

        $this->assertSame(['Student ID', 'Name', 'Programme', 'Department', 'Expiry date', 'Last appeal outcome', 'Exported on'], $rows[0]);
        $this->assertCount(4, $rows, 'header + 3 candidates');
        $this->assertSame('21030003', $rows[1][0], 'longest expired first, ID kept as text');
        $this->assertIsString($rows[1][0]);
        $this->assertSame('15 Jan 2027', $rows[1][4]);
        $this->assertSame('None', $rows[1][5]);
        $this->assertTrue(in_array($rows[1][6], [null, ''], true), 'Exported on is blank in the file');
    }

    public function test_the_header_is_bold_and_the_columns_have_widths(): void
    {
        $response = $this->actingAs($this->cgsUser)->post(route('candidacy.cgs.dismissals.export'));
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertGreaterThan(10, $sheet->getColumnDimension('C')->getWidth());
    }

    public function test_the_current_filters_are_respected_and_only_those_rows_are_recorded(): void
    {
        $rows = $this->sheet($this->actingAs($this->cgsUser)->post(route('candidacy.cgs.dismissals.export'), ['level' => 'master']));

        $this->assertSame(['21030002'], array_column(array_slice($rows, 1), 0));
        $this->assertSame(1, CandidacyDismissalExport::count());
    }

    public function test_every_export_records_one_row_per_student_and_the_list_shows_the_newest_time_without_changing(): void
    {
        $before = L::candidates()->pluck('studentId')->all();

        $this->actingAs($this->cgsUser)->post(route('candidacy.cgs.dismissals.export'))->assertOk();
        $this->assertSame(3, CandidacyDismissalExport::count());
        $this->assertSame($this->cgsUser->id, CandidacyDismissalExport::first()->exported_by);
        $this->assertSame($before, L::candidates()->pluck('studentId')->all(), 'nothing is locked or removed');
        $this->assertSame('2027-03-20 09:00', L::candidates()->first()['exportedAt']->format('Y-m-d H:i'));

        Carbon::setTestNow(Carbon::parse('2027-03-25 10:30:00', 'Asia/Kuala_Lumpur'));
        $this->actingAs($this->cgsUser)->post(route('candidacy.cgs.dismissals.export'))->assertOk();
        $this->assertSame(6, CandidacyDismissalExport::count());
        $this->assertSame('2027-03-25 10:30', L::candidates()->first()['exportedAt']->format('Y-m-d H:i'));
        $this->assertSame(['candidates' => 3, 'not_exported' => 0, 'exported' => 3], L::counts(L::candidates(), L::filters([])));
    }

    public function test_only_cgs_can_export(): void
    {
        $this->actingAs($this->student)->post(route('candidacy.cgs.dismissals.export'))->assertForbidden();
        $this->actingAs($this->lecturer)->post(route('candidacy.cgs.dismissals.export'))->assertForbidden();
        $this->assertSame(0, CandidacyDismissalExport::count());
    }
}
