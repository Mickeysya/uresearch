<?php

namespace App\Modules\Nureen\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\ApprovalHistory;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Nureen\Models\GaCertificationDetail;
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

        $chairDecision = $this->decision($application, 'chair', ['approved', 'endorsed']);
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
            : 'following approval by the Chair of Department'
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

        $period = $detail->period_start->format('j F Y').' to '.$detail->period_end->format('j F Y');

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
        $details += ['Semester' => $detail->semester, 'Period' => $period];

        $paragraphs = [
            "This is to certify that you served as a {$post} ({$detail->appointment_type}) at Universiti Teknologi PETRONAS from {$period}. The details of the assistantship are as follows:",
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
