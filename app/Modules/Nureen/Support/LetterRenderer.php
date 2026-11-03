<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Stage;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\Contracts\GeneratesQrCode;
use App\Modules\Nureen\Workflows\SupervisionWorkflow;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Builds and renders Nureen's two official letters -- the Supervision
 * appointment letter and the GA/GRA certification letter -- on the one
 * shared layout (Resources/views/letters/layout.blade.php).
 *
 * WHO SIGNS IS READ FROM approval_history, never passed in by the caller.
 * An official letter must only ever name someone who actually acted: the
 * Non-Executive CGS user who endorsed the appointment ('endorse'/'manual'),
 * the Senior Director CGS who approved the certification, or -- in
 * Supervision's 'automatic' mode, where no CGS user acts at all -- no
 * endorser, only a statement that CGS issued it following the approval of
 * the Chair of Department named in history. Re-rendering a letter later
 * (on revocation) therefore reproduces the same signatory and date.
 *
 * A DRAFT (the Preview letter button) is the same letter with the person
 * previewing in the signature block, "To be assigned on issue" for the
 * reference and a DRAFT watermark. Rendering one writes nothing and sends
 * nothing -- no reference is generated, no file stored, no one emailed.
 */
class LetterRenderer
{
    public const APPOINTMENT_DOC_TYPE = 'Supervisor Appointment Letter';

    public const CERTIFICATION_DOC_TYPE = 'GA/GRA Certification Letter';

    /** @var array<string, ?string> Downscaled logo per source path, for one request. */
    protected static array $logos = [];

    public function __construct(protected GeneratesQrCode $qr) {}

    /* -----------------------------------------------------------------
     | Appointment letter
     |------------------------------------------------------------------*/

    /** The appointment letter as PDF bytes. $previewer is required for a draft. */
    public function appointment(Application $application, bool $draft = false, ?User $previewer = null): string
    {
        return $this->render('nureen::letters.appointment', $this->appointmentData($application, $draft, $previewer));
    }

    /** @return array<string, mixed> */
    public function appointmentData(Application $application, bool $draft = false, ?User $previewer = null): array
    {
        $detail = SupervisionDetail::with('requestedSupervisor')->where('application_id', $application->id)->firstOrFail();
        $student = $application->student;
        $isCo = $detail->appointment_role === SupervisionDetail::ROLE_CO;
        $roleLabel = $isCo ? 'Co-Supervisor' : 'Main Supervisor';
        $mode = app(SupervisionWorkflow::class)->modeFor($application);

        // The Chair of Department, or the Dean of PGR for a Co-Supervisor
        // request under co_supervisor_approver = 'dean_pgr'.
        $approverKey = app(SupervisionWorkflow::class)->approverFor($application);
        $chairDecision = $this->decision($application, $approverKey, ['approved', 'endorsed']);
        $date = $draft ? now() : ($detail->letter_issued_at ?? now());

        if ($draft) {
            $signatory = $this->signatory($previewer, now());
        } elseif ($mode === 'automatic') {
            // No CGS user acted, so none is named.
            $signatory = null;
        } else {
            $endorsement = $this->decision($application, 'cgs_review', ['approved', 'endorsed']);
            $signatory = $endorsement ? $this->signatory($endorsement->approver, $endorsement->created_at) : null;
        }

        $reference = $draft ? null : $detail->letter_reference;

        $basis = $signatory
            ? 'following the endorsement of the Centre for Graduate Studies'
            : 'following approval by the '.($approverKey === 'dean_pgr' ? 'Dean of PGR' : 'Chair of Department')
                .($chairDecision?->approver ? ', '.$chairDecision->approver->name : '');

        // The supervisor is named once, in the details table (and the cc)
        // -- not again in paragraph 1, where a long name with titles would
        // cost the letter a line it may not have. See LetterWorstCaseTest.
        $paragraphs = [
            "We are pleased to inform you that, {$basis}, your {$roleLabel} has been appointed with effect from {$date->format('j F Y')}. The details of the appointment are as follows:",
            $isCo
                ? 'Your Co-Supervisor will work alongside your Main Supervisor in guiding your research. You are advised to keep a record of your supervisory meetings in accordance with the University\'s postgraduate regulations.'
                : 'Your Main Supervisor will guide your research and monitor your academic progress in accordance with the University\'s postgraduate regulations. You are advised to meet your supervisor regularly and to keep a record of your supervisory meetings.',
            'Should you have any queries regarding this appointment, please contact the Centre for Graduate Studies.',
        ];

        return $this->common($application, $student, $draft) + [
            'reference' => $reference,
            'date' => $date,
            'subject' => 'APPOINTMENT OF '.strtoupper($roleLabel),
            'paragraphs' => $paragraphs,
            'detailsAfter' => 0,
            'details' => [
                'Supervisor' => $detail->requestedSupervisor->name,
                'Role' => $roleLabel,
                'Appointment Type' => match ($detail->appointment_type) {
                    'existing' => 'Continuation of an existing supervisory arrangement',
                    'new' => 'New appointment',
                    default => 'Not recorded',
                },
                'Effective Date' => $date->format('j F Y'),
            ],
            'signatory' => $signatory,
            'issuedFollowing' => $signatory ? null : $chairDecision?->approver,
            'cc' => array_values(array_filter([
                "{$detail->requestedSupervisor->name}, {$roleLabel}",
                $chairDecision?->approver ? "{$chairDecision->approver->name}, Chair of Department" : null,
            ])),
            'verificationUrl' => $reference ? route('appointment.verify', $reference) : null,
            'revoked' => ! $draft && $detail->isRevoked(),
        ];
    }

    /* -----------------------------------------------------------------
     | Certification letter
     |------------------------------------------------------------------*/

    /** The certification letter as PDF bytes. $previewer is required for a draft. */
    public function certification(Application $application, bool $draft = false, ?User $previewer = null): string
    {
        return $this->render('nureen::letters.certification', $this->certificationData($application, $draft, $previewer));
    }

    /** @return array<string, mixed> */
    public function certificationData(Application $application, bool $draft = false, ?User $previewer = null): array
    {
        $detail = GaCertificationDetail::with('supervisor')->where('application_id', $application->id)->firstOrFail();
        $student = $application->student;
        $isGra = $detail->appointment_type === 'GRA';
        $post = $isGra ? 'Graduate Research Assistant' : 'Graduate Assistant';

        $approval = $this->decision($application, 'senior_director', ['approved']);
        $signatory = $draft
            ? $this->signatory($previewer, now())
            : ($approval ? $this->signatory($approval->approver, $approval->created_at) : null);
        $date = $draft ? now() : ($approval?->created_at ?? now());
        $reference = $draft ? null : $detail->reference_number;

        // config('nureen.ga_certification.ask_period'): when the form stops
        // asking for the period, the letter stops printing one.
        $period = config('nureen.ga_certification.ask_period') && $detail->period_start && $detail->period_end
            ? $detail->period_start->format('j F Y').' to '.$detail->period_end->format('j F Y')
            : null;

        // CGS's online form: level, study type and mode, e.g. "Master,
        // Research, Full-time ODL". The identity number and personal email
        // are for CGS's records, not the letter.
        $options = config('nureen.ga_certification.options');
        $study = collect([
            $options['level_of_study'][$detail->level_of_study] ?? null,
            $options['study_level'][$detail->study_level] ?? null,
            $options['mode_of_study'][$detail->mode_of_study] ?? null,
        ])->filter()->implode(', ');

        $details = $isGra
            ? [
                'Research Project' => $detail->project_title,
                'Project Leader' => $detail->project_leader_name,
                'Grant/Project Code' => $detail->grant_code,
            ]
            : [
                'Course Code' => $detail->course_code,
                'Course Name' => $detail->course_name,
                'Course Owner' => $detail->course_owner_name,
            ];
        $details += ['Semester' => $detail->semester, 'Period' => $period, 'Level of Study' => $study ?: null];

        $when = $period ? "from {$period}" : ($detail->semester ? "during the {$detail->semester} semester" : null);

        $paragraphs = [
            "This is to certify that you served as a {$post} ({$detail->appointment_type}) at Universiti Teknologi PETRONAS"
                .($when ? " {$when}" : '').'. The details of the assistantship are as follows:',
        ];

        if ($detail->purpose) {
            $paragraphs[] = "This letter is issued at your request for the following purpose: {$detail->purpose}.";
        }

        $paragraphs[] = 'The University thanks you for your contribution to its '.($isGra ? 'research' : 'teaching').' activities.';

        $supervisor = $detail->supervisor ?? $student?->supervisor;

        return $this->common($application, $student, $draft) + [
            'reference' => $reference,
            'date' => $date,
            'subject' => 'CERTIFICATION OF '.strtoupper($post),
            'paragraphs' => $paragraphs,
            'detailsAfter' => 0,
            // A GRA with no grant code: leave the row out rather than print a dash.
            'details' => array_filter($details, fn ($value) => filled($value)),
            'signatory' => $signatory,
            'issuedFollowing' => null,
            'cc' => $supervisor ? ["{$supervisor->name}, Supervisor"] : [],
            'verificationUrl' => $reference ? route('certificate.verify', $reference) : null,
            'revoked' => ! $draft && $detail->isRevoked(),
        ];
    }

    /* -----------------------------------------------------------------
     | The course owner's attachment (GA/GRA certification)
     |------------------------------------------------------------------*/

    /**
     * CGS's "Attachment for Request for GA/GRA Certification Letter"
     * (docs/forms/GA_GRA_Certification_form_-_Attachment.pdf), recreated
     * and pre-filled: the student's name and programme (from their profile),
     * today's date, and the first course row from what they entered. The
     * signature and stamp stay blank -- the course owner signs it, and the
     * student uploads the signed copy as the supporting document.
     *
     * A GRA has no course: its row carries the grant/project code, the
     * project title and the project leader, under the same three columns.
     */
    public function certificationAttachment(Application $application): string
    {
        return $this->render('nureen::letters.certification_attachment', $this->certificationAttachmentData($application));
    }

    /** @return array<string, mixed> */
    public function certificationAttachmentData(Application $application): array
    {
        $detail = GaCertificationDetail::where('application_id', $application->id)->first();
        $student = $application->student;
        $isGra = $detail?->appointment_type === 'GRA';

        $first = $isGra
            ? ['code' => $detail?->grant_code, 'course' => $detail?->project_title, 'owner' => $detail?->project_leader_name]
            : ['code' => $detail?->course_code, 'course' => $detail?->course_name, 'owner' => $detail?->course_owner_name];

        return [
            'name' => $student?->name,
            'matric' => $student?->matric_no,
            'programme' => $student?->programme,
            'date' => now(),
            'isGra' => $isGra,
            // Five rows, as on CGS's form; only the first is pre-filled.
            'rows' => array_merge([$first], array_fill(0, 4, ['code' => null, 'course' => null, 'owner' => null])),
            'verificationUrl' => null,
        ];
    }

    /* -----------------------------------------------------------------
     | The GA Extension Form (UTP/CGS/001-A2)
     |------------------------------------------------------------------*/

    /**
     * CGS's GA Extension Form, generated from what the student entered
     * instead of uploaded: Section A's ticks from the documents actually
     * attached, B-D from the detail row, E from approval_history, J from
     * CGS's remarks. Rendered fresh on every download, so it shows the
     * application's progress so far.
     *
     * $viewer decides masking: the account and IC/passport numbers print in
     * full only for the CGS stage's role (GaExtensionDetail::
     * revealsSensitiveTo()).
     */
    public function gaExtensionForm(Application $application, ?User $viewer): string
    {
        $pdf = Pdf::loadView('nureen::letters.ga_extension_form', $this->gaExtensionFormData($application, $viewer))
            ->setPaper('a4')
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);

        // "Page X of Y", bottom right as on the paper form: the total is
        // only known once laid out, and Dompdf has no counter(pages).
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'bold');
        $canvas->page_text($canvas->get_width() - 92, $canvas->get_height() - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 6.6);

        return $pdf->output();
    }

    /** @return array<string, mixed> */
    public function gaExtensionFormData(Application $application, ?User $viewer): array
    {
        $detail = GaExtensionDetail::where('application_id', $application->id)->first() ?? new GaExtensionDetail;
        $student = $application->student()->with('supervisor')->first();
        $config = config('nureen.ga_extension');

        // Section E: who acted at each stage of THIS application's chain.
        // Only decisions after the latest return count -- a return sends
        // the application back, and the stages it skipped back over must be
        // signed again.
        $chain = collect(app(WorkflowEngine::class)->stagesFor($application))->keyBy(fn (Stage $stage) => $stage->key);
        $history = ApprovalHistory::with('approver')->where('application_id', $application->id)->orderBy('id')->get();
        $lastReturn = $history->where('decision', 'returned')->max('id') ?? 0;
        $current = $history->filter(fn (ApprovalHistory $row) => $row->id > $lastReturn && $row->decision !== 'returned');

        $endorsement = function (string $key) use ($chain, $current): ?array {
            if (! $chain->has($key)) {
                return null;
            }

            $row = $current->where('stage_key', $key)->last();

            return $row ? [
                'name' => $row->approver?->name,
                'role' => $row->approver ? Role::label($row->approver->role) : $row->stage_label,
                'decision' => ucfirst($row->decision),
                'at' => $row->created_at,
                'passed' => $row->decision !== 'rejected',
            ] : null;
        };

        $endorsements = [
            'supervisor' => $endorsement('supervisor'),
            'cgs' => $endorsement('cgs_verify'),
            'senior_director' => $endorsement('senior_director'),
        ];

        // Section A: a student tick per item with a document attached; the
        // CGS tick on those once CGS has passed the application.
        $byType = $application->documents()->get()->groupBy('doc_type');
        $cgsPassed = (bool) ($endorsements['cgs']['passed'] ?? false);
        $required = $config['required_documents'];
        $items = [
            ['Copy of Student Visa / Identification Card (IC)', [$required['identity_document_documents'] ?? null]],
            ['Copy of Admission Letter & Offer letter', [$required['admission_letter_documents'] ?? null]],
            ['Supporting Letter from Supervisor', [$required['supervisor_letter_documents'] ?? null]],
            [null, [...array_values($config['stage_gate_documents']), $config['legacy_documents']['stage_gate_proof']]],
        ];
        $checklist = array_map(function (array $item) use ($byType, $cgsPassed) {
            $attached = array_values(array_filter($item[1], fn ($label) => $label && $byType->has($label)));

            return ['label' => $item[0], 'attached' => $attached, 'student' => $attached !== [], 'cgs' => $attached !== [] && $cgsPassed];
        }, $items);

        $remarks = $detail->cgs_remarks ?? [];

        return [
            'student' => $student,
            'detail' => $detail,
            'application' => $application,
            'logo' => $this->logo(),
            'checklist' => $checklist,
            'bank' => (bool) $config['collect_bank_details'],
            'account' => $detail->sensitiveFor('bank_account_no', $viewer),
            'identity' => $detail->sensitiveFor('identity_number', $viewer),
            // At least three rows, as on the paper form.
            'publications' => array_pad($detail->publicationRows(), 3, null),
            'declaration' => $config['declaration'],
            'endorsements' => $endorsements,
            'remarks' => collect($config['cgs_remarks_fields'])->map(fn (array $field, string $key) => [
                'label' => $field['label'],
                'value' => filled($remarks[$key] ?? null) && ($field['type'] ?? 'text') === 'date'
                    ? \Illuminate\Support\Carbon::parse($remarks[$key])->format('j M Y')
                    : ($remarks[$key] ?? null),
            ])->all(),
            'status' => $this->progressLabel($application),
            'generatedAt' => now(),
            'verificationUrl' => null,
        ];
    }

    /** "Draft", "Pending: Non-Executive CGS", "Approved"... for the form's footer. */
    protected function progressLabel(Application $application): string
    {
        if ($application->status === Application::STATUS_PENDING) {
            $stage = app(WorkflowEngine::class)->currentStage($application);

            return 'Pending'.($stage ? ': '.$stage->label : '');
        }

        return ucfirst((string) $application->status);
    }

    /* -----------------------------------------------------------------
     | Storage
     |------------------------------------------------------------------*/

    /**
     * Overwrites the already-stored letter with $contents, keeping its
     * ApplicationDocument row (and so every link and email that points at
     * it). Used on revocation, so the letter downloaded afterwards -- through
     * Core's documents.show as much as through Nureen's own routes -- carries
     * the REVOKED watermark. Core's DocumentStore has no "replace" yet; see
     * SHARED_CHANGES.md §7 for the method this should become.
     */
    public function replaceStored(Application $application, string $docType, string $contents): void
    {
        $document = $application->documents()->where('doc_type', $docType)->latest('id')->first();

        if (! $document) {
            return;
        }

        Storage::disk('local')->put($document->path, $contents);
        $document->update(['size_bytes' => strlen($contents)]);
    }

    /* -----------------------------------------------------------------
     | Shared
     |------------------------------------------------------------------*/

    /** @return array<string, mixed> */
    protected function common(Application $application, ?User $student, bool $draft): array
    {
        return [
            'letterhead' => config('nureen.letters'),
            'logo' => $this->logo(),
            'addressee' => [
                'name' => $student?->name ?? '—',
                'matric' => $student?->matric_no,
                'programme' => $student?->programme,
                'department' => $student?->department,
            ],
            // users has no title column (and Core's users table is not
            // Nureen's to extend), so the salutation is the name alone --
            // which already carries "Dr." where a seeded name has one.
            'salutation' => $student?->name ?? 'Sir/Madam',
            'draft' => $draft,
        ];
    }

    /**
     * The latest approval_history row at $stageKey with one of $decisions,
     * with its approver -- the one record of who actually acted.
     */
    protected function decision(Application $application, string $stageKey, array $decisions): ?ApprovalHistory
    {
        return ApprovalHistory::with('approver')
            ->where('application_id', $application->id)
            ->where('stage_key', $stageKey)
            ->whereIn('decision', $decisions)
            ->latest('id')
            ->first();
    }

    /** @return array{name: string, title: string, at: CarbonInterface}|null */
    protected function signatory(?User $user, CarbonInterface $at): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'name' => $user->name,
            'title' => config("nureen.letters.signatory_titles.{$user->role}") ?? Role::label($user->role),
            'at' => $at,
        ];
    }

    /**
     * The configured logo as a data URI, downscaled to 600 px wide with GD
     * so a large source image does not bloat every letter -- or null when
     * the file is missing or unreadable, which the layout turns into a
     * text-only letterhead. Never fetched over the network.
     */
    public function logo(): ?string
    {
        $path = (string) config('nureen.letters.logo_path');

        if (array_key_exists($path, static::$logos)) {
            return static::$logos[$path];
        }

        $raw = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        if ($raw === false || $raw === '') {
            return static::$logos[$path] = null;
        }

        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring($raw) : false;

        if (! $image) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw) ?: 'image/png';

            return static::$logos[$path] = str_starts_with($mime, 'image/') ? "data:{$mime};base64,".base64_encode($raw) : null;
        }

        $width = min(600, imagesx($image));
        $height = (int) round(imagesy($image) * $width / imagesx($image));
        $scaled = imagecreatetruecolor($width, $height);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        ob_start();
        imagepng($scaled, null, 9);

        return static::$logos[$path] = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /** Forgets the cached logo -- for tests that swap the configured file. */
    public static function forgetLogo(): void
    {
        static::$logos = [];
    }

    /** @param  array<string, mixed>  $data */
    protected function render(string $view, array $data): string
    {
        $data['qrDataUri'] = $data['verificationUrl'] ? $this->qr->dataUri($data['verificationUrl']) : null;

        return Pdf::loadView($view, $data)
            ->setPaper('a4')
            ->setOption([
                // Embed only the glyphs actually used: the full DejaVu font
                // is what made each letter ~880 KB before.
                'isFontSubsettingEnabled' => true,
                'defaultFont' => 'DejaVu Serif',
                'isRemoteEnabled' => false,
            ])
            ->output();
    }
}
