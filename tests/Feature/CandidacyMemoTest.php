<?php

namespace Tests\Feature;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use App\Modules\Chloe\Services\CandidacyMemoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCandidacyWorld;
use Tests\Support\MakesUsers;
use Tests\TestCase;

/** Phase 3: the memo is made on Dean approval, is a real A4 PDF carrying every stamp, and can be verified. */
class CandidacyMemoTest extends TestCase
{
    use BuildsCandidacyWorld;
    use MakesUsers;
    use RefreshDatabase;

    protected function approved(): CandidacyAppeal
    {
        return $this->service->decide($this->deanUser, $this->toStage('dean', 3), 'approve');
    }

    /** Text of the stored memo via Ghostscript (present in the app container); null when it is not installed. */
    protected function memoText(CandidacyAppeal $appeal): ?string
    {
        $gs = trim((string) shell_exec('command -v gs'));

        if ($gs === '') {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'memo');
        file_put_contents($file, Storage::disk('local')->get($appeal->memo_path));
        $text = (string) shell_exec(escapeshellarg($gs).' -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -sOutputFile=- '.escapeshellarg($file).' 2>/dev/null');
        @unlink($file);

        return preg_replace('/\s+/', ' ', $text);
    }

    public function test_dean_approval_stores_a_two_page_a4_pdf_with_the_agreed_file_name(): void
    {
        $appeal = $this->approved();

        $this->assertNotNull($appeal->memo_path);
        Storage::disk('local')->assertExists($appeal->memo_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($appeal->memo_path));
        $this->assertSame(2, preg_match_all('~/Type\s*/Page[^s]~', Storage::disk('local')->get($appeal->memo_path)));
        $this->assertMatchesRegularExpression('~^candidacy/memos/\d+-[A-Za-z0-9]{24}\.pdf$~', $appeal->memo_path);
        $this->assertSame('SCA-22001001-01_Ahmad Danial_22001001_Memo - Appeal for Extension.pdf', $appeal->memo_filename);
        $this->assertNotNull($appeal->memo_generated_at);
    }

    public function test_the_memo_text_carries_every_stamp_the_codes_and_the_decision(): void
    {
        $text = $this->memoText($appeal = $this->approved());

        if ($text === null) {
            $this->markTestSkipped('Ghostscript is not installed here.');
        }

        $this->assertStringContainsString('MEMORANDUM', $text);
        $this->assertStringContainsString('ENDORSEMENT AND APPROVAL RECORD', $text);
        $this->assertStringContainsString('APPROVED: 3 month(s)', $text);
        $this->assertStringContainsString('Ref SCA-22001001-01', $text);
        $this->assertStringContainsString('(GMT+8)', $text);

        foreach ($appeal->approvals as $row) {
            $this->assertStringContainsString(in_array($row->stage, ['cgs'], true) ? $row->verification_code : 'Code '.$row->verification_code, $text, "stamp of {$row->stage}");
            $this->assertStringContainsString($row->full_name, $text);
        }

        $this->assertStringContainsString('Digitally approved by Prof. Dean', $text);
        $this->assertStringContainsString('01 Nov 2026, 10:00', $text); // the app clock is already Kuala Lumpur time
    }

    public function test_the_memo_file_name_is_safe(): void
    {
        $appeal = new CandidacyAppeal(['appeal_no' => 'SCA-1-01']);

        $this->assertSame('SCA-1-01_A B_1 2 3_Memo - Appeal for Extension.pdf', CandidacyMemoService::fileName($appeal, "A/B\\", '1:2*3?'));
    }

    public function test_verify_command_reports_ok_and_then_mismatch_when_a_record_changes(): void
    {
        $appeal = $this->approved();

        $this->artisan('candidacy:verify-memo', ['appeal_no' => $appeal->appeal_no])->expectsOutputToContain('OK')->assertSuccessful();

        CandidacyAppealApproval::where('candidacy_appeal_id', $appeal->id)->where('stage', 'chair')->update(['full_name' => 'x', 'verification_code' => 'AAAAAAAAAA']);
        $this->artisan('candidacy:verify-memo', ['appeal_no' => $appeal->appeal_no])->expectsOutputToContain('MISMATCH')->assertFailed();
    }

    public function test_verify_command_detects_a_changed_signature_copy(): void
    {
        $appeal = $this->approved();
        $copy = $appeal->approvals()->where('stage', 'supervisor')->first()->signature_snapshot_path;
        Storage::disk('local')->put($copy, 'tampered');

        $this->artisan('candidacy:verify-memo', ['appeal_no' => $appeal->appeal_no])->expectsOutputToContain('signature copy changed')->assertFailed();
    }

    public function test_only_student_named_people_and_cgs_may_download_the_memo(): void
    {
        $appeal = $this->approved();

        foreach ([$this->student, $this->lecturer, $this->chairUser, $this->deanUser, $this->cgsUser] as $user) {
            $this->assertTrue($user->can('downloadMemo', $appeal), $user->role);
        }

        $this->assertFalse($this->student('22009999', 'x@test.my')->can('downloadMemo', $appeal));
        $this->assertFalse($this->supervisor('o@test.my')->can('downloadMemo', $appeal));
    }

    public function test_final_approval_mail_attaches_the_memo(): void
    {
        $appeal = $this->approved();

        \Illuminate\Support\Facades\Notification::assertSentTo($this->student, \App\Modules\Chloe\Notifications\CandidacyAppealNotice::class,
            fn ($n) => $n->template === 'final_student' && $n->memoPath === $appeal->memo_path && $n->memoName === $appeal->memo_filename);
    }
}
