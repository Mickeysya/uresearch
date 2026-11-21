<?php

namespace App\Modules\Nureen\Support\Demo;

use App\Modules\Core\Models\User;
use App\Modules\Nureen\Support\LetterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Readable one-page supporting documents for `nureen:demo-fresh`: a
 * supervisor's letter that reads like one, an offer letter with the
 * programme and admission date, an identity document copy, and so on --
 * each naming the student and their matric number, rendered by Dompdf from
 * nureen::demo.supporting_document. Each carries a small footer saying it
 * is a sample, so none can pass for a real record.
 *
 * Handed to the real upload fields as UploadedFile objects, under a name a
 * person would give the file ("Supporting-Letter-Ravi-Selvam.pdf"), so
 * DocumentStore stores them exactly as it stores a student's upload.
 */
class DemoDocumentFactory
{
    /** kind => [title on the page, file name prefix] */
    public const KINDS = [
        'supervisor_letter' => ["Supervisor's Supporting Letter", 'Supporting-Letter'],
        'admission_offer' => ['Offer of Admission', 'Admission-Offer-Letter'],
        'identity' => ['Identity Document (Copy)', 'IC-Copy'],
        'acceptance_proof' => ['Notification of Manuscript Acceptance', 'Journal-Acceptance-Letter'],
        'research_proposal' => ['Research Proposal Summary', 'Research-Proposal'],
        'evidence_of_work' => ['Evidence of Work Done', 'Evidence-of-Work-Done'],
        'justification_letter' => ['Attendance Justification Letter', 'Justification-Letter'],
        'activity_breakdown' => ['Monthly Activity Breakdown', 'Monthly-Activity-Breakdown'],
        'resume' => ['Curriculum Vitae', 'Resume'],
        'transcript' => ['Academic Transcript', 'Academic-Transcript'],
        'interview_report' => ['GA Interview Report', 'Interview-Report'],
    ];

    protected ?string $directory = null;

    /** @param  array<string, mixed>  $extra  kind-specific values for the view */
    public function file(User $student, string $kind, array $extra = []): UploadedFile
    {
        [, $prefix] = self::KINDS[$kind];
        // "Supporting-Letter-Ravi-Selvam.pdf": the prefix as written, then
        // the student's name with each part capitalised.
        $student_part = implode('-', array_map('ucfirst', explode('-', Str::slug($student->name, '-', null))));
        $name = "{$prefix}-{$student_part}.pdf";

        $path = $this->directory().DIRECTORY_SEPARATOR.Str::random(8).'-'.$name;
        file_put_contents($path, $this->pdf($student, $kind, $extra));

        // test: true -- a file this process wrote itself, not an HTTP upload,
        // so is_uploaded_file() would be false; everything else (mime
        // sniffing, size, storing) is the real path.
        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function pdf(User $student, string $kind, array $extra = []): string
    {
        $student->loadMissing('supervisor');

        return Pdf::loadView('nureen::demo.supporting_document', [
            'kind' => $kind,
            'title' => self::KINDS[$kind][0],
            'student' => $student,
            'supervisor' => $student->supervisor,
            'logo' => app(LetterRenderer::class)->logo(),
            'today' => now(),
            'extra' => $extra,
        ])
            ->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])
            ->output();
    }

    /** Deletes the temporary copies; the stored documents are DocumentStore's. */
    public function cleanUp(): void
    {
        if ($this->directory && is_dir($this->directory)) {
            array_map('unlink', glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: []);
            @rmdir($this->directory);
        }

        $this->directory = null;
    }

    protected function directory(): string
    {
        if (! $this->directory) {
            $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nureen-demo-'.Str::random(10);
            mkdir($this->directory, 0700, true);
        }

        return $this->directory;
    }
}
