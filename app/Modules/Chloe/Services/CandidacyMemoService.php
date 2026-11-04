<?php

namespace App\Modules\Chloe\Services;

use App\Modules\Chloe\Models\CandidacyAppeal;
use App\Modules\Chloe\Models\CandidacyAppealApproval;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The digitally approved memo, built when the Dean approves. Black and white, A4, Tahoma. Every approver's stamp carries
 * the appeal number and a verification code (CandidacyVerification) and the signature image COPIED at decision time, so a
 * later signature change never alters an issued memo. `php artisan candidacy:verify-memo <appeal_no>` re-computes the codes.
 */
class CandidacyMemoService
{
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    /** @return array{0:string,1:string} [stored path, download file name] */
    public function generate(CandidacyAppeal $appeal): array
    {
        $appeal->loadMissing(['department', 'supervisorProfile', 'chairProfile', 'deanProfile', 'publications']);
        $identity = $appeal->identity();
        $approvals = $this->latestApprovals($appeal);

        $pdf = Pdf::loadView('chloe::candidacy.memo.appeal_memo', [
            'appeal' => $appeal,
            'student' => $identity,
            'approvals' => $approvals,
            'logo' => $this->greyLogo(),
            'signatures' => $approvals->map(fn (CandidacyAppealApproval $a) => $this->signatureUri($a)),
            'when' => fn ($at) => $at->copy()->setTimezone(self::TIMEZONE)->format('d M Y, H:i'),
            'stamp' => fn (CandidacyAppealApproval $a) => $this->stampText($appeal, $a),
        ])->setPaper('a4');

        $this->registerFonts($pdf->getDomPDF());

        $path = 'candidacy/memos/'.$appeal->id.'-'.Str::random(24).'.pdf';
        Storage::disk('local')->put($path, $pdf->output());

        return [$path, self::fileName($appeal, $identity?->name ?? 'Student', $identity?->studentId ?? '')];
    }

    /** "Digitally approved by {name}, {designation} on {dd Mon yyyy, hh:mm} (GMT+8) · Ref {appeal_no} · Code {code}" */
    public function stampText(CandidacyAppeal $appeal, CandidacyAppealApproval $approval): string
    {
        $verb = $approval->decision === 'verified' ? 'verified' : 'approved';
        $when = $approval->decided_at->copy()->setTimezone(self::TIMEZONE)->format('d M Y, H:i');

        return "Digitally {$verb} by {$approval->full_name}, {$approval->designation} on {$when} (GMT+8) · Ref {$appeal->appeal_no} · Code {$approval->verification_code}";
    }

    /** The newest approving decision of each stage (a later round replaces an earlier one). */
    public function latestApprovals(CandidacyAppeal $appeal)
    {
        return $appeal->approvals()->whereIn('decision', ['approved', 'verified'])->orderBy('round')->orderBy('id')->get()
            ->groupBy('stage')->map->last()
            ->sortBy(fn ($a) => array_search($a->stage, ['supervisor', 'chair', 'cgs', 'dean'], true));
    }

    public static function fileName(CandidacyAppeal $appeal, string $studentName, string $studentId): string
    {
        $clean = fn (string $part) => trim(preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', ' ', $part));

        return $clean($appeal->appeal_no).'_'.$clean($studentName).'_'.$clean($studentId).'_Memo - Appeal for Extension.pdf';
    }

    protected function signatureUri(CandidacyAppealApproval $approval): ?string
    {
        if (! $approval->signature_snapshot_path || ! Storage::disk('local')->exists($approval->signature_snapshot_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($approval->signature_snapshot_path));
    }

    /** The memo is black and white: the logo is converted to grey scale in memory (the file itself is untouched). */
    protected function greyLogo(): ?string
    {
        $file = public_path('images/UTP_logo.png');
        $img = is_file($file) ? @imagecreatefrompng($file) : false;

        if (! $img) {
            return $this->dataUri($file, 'image/png');
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    protected function dataUri(string $file, string $mime): ?string
    {
        return is_file($file) ? "data:{$mime};base64,".base64_encode((string) file_get_contents($file)) : null;
    }

    protected function registerFonts(\Dompdf\Dompdf $dompdf): void
    {
        File::ensureDirectoryExists(config('dompdf.options.font_dir', storage_path('fonts')));

        $fonts = app_path('Modules/Chloe/Resources/fonts');
        $metrics = $dompdf->getFontMetrics();
        $metrics->registerFont(['family' => 'Tahoma', 'weight' => 'normal', 'style' => 'normal'], "{$fonts}/Tahoma.ttf");
        $metrics->registerFont(['family' => 'Tahoma', 'weight' => 'bold', 'style' => 'normal'], "{$fonts}/Tahoma-Bold.ttf");
    }
}
