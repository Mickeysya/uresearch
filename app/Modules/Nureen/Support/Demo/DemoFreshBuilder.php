<?php

namespace App\Modules\Nureen\Support\Demo;

use App\Modules\Core\Contracts\SuppliesApprovedGaApplication;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\CgsAssignment;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Core\Support\Stage;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\TermsAcceptance;
use App\Modules\Haziq\Support\Roles as HaziqRoles;
use App\Modules\Nureen\Models\AttendanceAlert;
use App\Modules\Nureen\Models\AttendanceRecord;
use App\Modules\Nureen\Models\AttendanceRiskState;
use App\Modules\Nureen\Models\DemoRecord;
use App\Modules\Nureen\Models\GaAppointmentRecord;
use App\Modules\Nureen\Models\GaCertificationDetail;
use App\Modules\Nureen\Models\GaExtensionDetail;
use App\Modules\Nureen\Models\SupervisionDetail;
use App\Modules\Nureen\Support\AttendanceEarlyWarning;
use App\Modules\Nureen\Support\AttendanceIngestor;
use App\Modules\Nureen\Support\AttendanceSheet;
use App\Modules\Nureen\Support\GaExtensionRules;
use App\Modules\Nureen\Support\SupervisorCheck;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds `nureen:demo-fresh`'s data: four applications per module at the
 * stages the demo needs, plus four students' attendance.
 *
 * NOTHING HERE WRITES A STATUS OR A STAGE. Every submission and decision is
 * a real HTTP request, as the real user, to the real route (DemoHttp), so
 * the controllers do what they always do: validate, store the uploads
 * through DocumentStore, call WorkflowEngine, write approval_history, send
 * the emails, assign CGS/... references and render the letters with the
 * current templates. The only rows written directly are the ones no screen
 * creates: the GA/GRA appointment record the certification check reads
 * (ga_appointment_records, the stand-in GaAppointmentRecordSeeder also
 * fills), and the attendance, which goes through the same AttendanceSheet
 * -> AttendanceIngestor -> AttendanceEarlyWarning path as a CGS upload.
 *
 * ONLY FRESH ACCOUNTS. Students are taken from the existing demo accounts
 * that have no applications, attendance, GA/GRA profile or appointment
 * record at all, so nothing here adds to or alters anyone's existing
 * record. Staff are the existing accounts (admin@, zulkifly.cgs@, chair@,
 * director@, each student's own supervisor and Research Centre).
 *
 * Every row created is listed in nureen_demo_records (DemoRecord) as soon
 * as it exists, so `--reset` can remove exactly those, even after a run
 * that failed half-way.
 */
class DemoFreshBuilder
{
    public const MARKER = '[DEMO]';

    /** Sessions per weekly UTrace period. */
    public const SESSIONS_PER_WEEK = 20;

    /**
     * Sessions attended in weeks 1-6, out of SESSIONS_PER_WEEK -- checked
     * against the real AttendanceProjector and rule when this was written:
     *   good       93.3% so far, projected 92.2%: not flagged
     *   projected  90.8% so far, no week below 80%, but falling 3 points a
     *              week: projected 78.8%, flagged by the projection only
     *   rule       70.8% so far, last week 75% (and improving, projected
     *              84.1%): flagged by the 80% rule only
     *   appeal     74.2% so far, projected 49.6%: flagged by both; this
     *              student then files an attendance appeal
     */
    public const ATTENDANCE = [
        'good' => [19, 19, 18, 19, 18, 19],
        'projected' => [20, 19, 18, 18, 17, 17],
        'rule' => [12, 13, 14, 15, 16, 15],
        'appeal' => [18, 17, 15, 14, 13, 12],
    ];

    /** @var Collection<int, User> students not yet given a demo record */
    protected Collection $pool;

    /** @var array<int, array{module: string, case: string, application: ?Application, student: User, stage?: string, login?: string}> */
    protected array $results = [];

    /** @var array<int, string> */
    protected array $warnings = [];

    public function __construct(
        protected DemoHttp $http,
        protected DemoDocumentFactory $documents,
        protected WorkflowEngine $engine,
        protected CgsAssignment $assignment,
    ) {}

    /**
     * @return array{rows: array<int, array<int, string>>, warnings: array<int, string>}
     */
    public function build(): array
    {
        $this->pool = $this->freshStudents();

        try {
            $this->gaExtensions();
            $this->supervisions();
            $this->certifications();
            $this->attendance();
        } finally {
            $this->documents->cleanUp();
        }

        return ['rows' => $this->rows(), 'warnings' => $this->warnings];
    }

    /* ---------------------------------------------------------------
     | GA Extension  (Admin -> CGS -> Supervisor -> Senior Director)
     |---------------------------------------------------------------*/

    protected function gaExtensions(): void
    {
        $students = $this->take(4, 'GA Extension',
            fn (User $s) => $this->researchCentre($s) !== null,
            fn (User $s) => str_contains(strtolower((string) $s->programme), 'full'));

        [$currentEnd, $start] = $this->extensionDates();

        foreach ($students as $i => $student) {
            $this->approvedGaApplication($student, $currentEnd);
            $application = $this->submitGaExtension($student, $currentEnd, $start, $i);

            match ($i) {
                // 1. Just submitted: waiting at Admin.
                0 => null,
                // 2. Admin has verified it: waiting at CGS, every System check passing.
                1 => $this->advance($application, 'ga-extension', 'cgs_verify'),
                // 3. CGS sent it back to Admin with a reason (the real Return button).
                2 => $this->returnGaExtension($application),
                // 4. Approved by the Senior Director; the GA Extension Form is generated.
                3 => $this->advance($application, 'ga-extension', null),
            };

            if ($i === 1) {
                $this->checkGaExtensionRules($application);
            }

            if ($i === 3) {
                // The generated form must open for the student, as on the Documents page.
                $this->http->get($student, route('ga-extension.form-pdf.view', $application));
            }

            $this->results[] = [
                'module' => 'GA Extension',
                'case' => ['Submitted, waiting at Admin', 'Waiting at CGS, System checks passing', 'Returned by CGS to Admin, with a reason', 'Approved, GA Extension Form generated'][$i],
                'application' => $application,
                'student' => $student,
            ];
        }
    }

    /**
     * The approved GA Application the GA Extension form requires, made the
     * way a student makes one: Haziq's real terms, form and five-stage
     * chain, ending on $serviceEnd so the extension starts on an allowed
     * day. Approving the extension later moves THIS end date, not one the
     * student already had.
     */
    protected function approvedGaApplication(User $student, Carbon $serviceEnd): Application
    {
        [$level, $admission] = $this->studyProfile($student, $serviceEnd->copy()->addDay());

        $this->http->post($student, route('ga-application.terms.accept'), ['agree' => '1']);
        $this->rememberWhere(new TermsAcceptance, 'user_id', $student->id);

        $this->http->post($student, route('ga-application.store'), [
            'department_unit' => $student->department,
            'purpose' => self::MARKER.' Graduate Assistant: tutorials and laboratory classes',
            'proposed_hours_per_week' => '12',
            'service_start_date' => $serviceEnd->copy()->subYear()->addDay()->toDateString(),
            'service_end_date' => $serviceEnd->toDateString(),
            'proposed_allowance' => '1500',
            'level_of_study' => $level === 'phd' ? 'phd' : 'master',
            'programme' => $student->programme ?: ($level === 'phd' ? 'PhD Full-Time' : 'MSc Full-Time'),
            'admission_date' => $admission->toDateString(),
            'academic' => [[
                'level_of_study' => 'Bachelor', 'institution' => 'Universiti Teknologi PETRONAS',
                'programme' => $student->department === 'Computing' ? 'Computer Science' : 'Engineering',
                'result' => 'CGPA 3.62', 'year_from' => (string) ($admission->year - 4), 'year_to' => (string) ($admission->year - 1),
            ]],
        ], [
            'resume' => $this->documents->file($student, 'resume'),
            'academic_transcript' => $this->documents->file($student, 'transcript'),
            'visa_or_ic' => $this->documents->file($student, 'identity', ['identity_number' => $this->identityNumber($student)]),
        ]);

        $application = $this->latest($student, 'ga_application');
        $this->rememberWhere(new GraGaStudentProfile, 'user_id', $student->id);

        $this->advance($application, 'ga-application', 'research_centre_interview');

        $rc = $this->researchCentre($student);
        $date = today()->subDay();
        $this->http->post($rc, route('ga-application.schedule-interview', $application), [
            'interview_date' => $date->toDateString(),
            'interview_start_hour' => '10', 'interview_start_minute' => '00', 'interview_start_meridiem' => 'AM',
            'interview_end_hour' => '10', 'interview_end_minute' => '45', 'interview_end_meridiem' => 'AM',
        ]);
        $this->http->post($rc, route('ga-application.decide', $application), [
            'decision' => 'approve',
            'interview_notes' => 'Clear explanation of the research; ready to run tutorials. Recommended.',
        ], [
            'interview_report' => $this->documents->file($student, 'interview_report', ['date' => $date->format('j F Y'), 'interviewer' => $rc->name]),
        ]);

        $this->advance($application, 'ga-application', null);

        if ($application->refresh()->status !== Application::STATUS_APPROVED) {
            throw new DemoStepFailed("GA Application {$application->reference()} for {$student->email} did not reach Approved.");
        }

        $approved = app()->bound(SuppliesApprovedGaApplication::class)
            ? app(SuppliesApprovedGaApplication::class)->approvedFor($student)
            : null;

        if (! $approved || ! $approved['service_end_date']->isSameDay($serviceEnd)) {
            throw new DemoStepFailed("The GA Extension form would not see {$student->email}'s demo GA Application as their approved one.");
        }

        return $application;
    }

    protected function submitGaExtension(User $student, Carbon $currentEnd, Carbon $start, int $i): Application
    {
        [$level, $admission] = $this->studyProfile($student, $start);
        $rules = config('nureen.ga_extension.rules');
        $months = max($rules['extension_months']['min'], min(6, $rules['extension_months']['max']));
        $candidacyEnd = $admission->copy()->addYearsNoOverflow($rules['max_candidacy_years'][$level])->subDay();
        $paper = $this->paper($i);

        $files = [];
        foreach (config('nureen.ga_extension.required_documents') as $field => $label) {
            $files[$field] = [match ($field) {
                'identity_document_documents' => $this->documents->file($student, 'identity', ['identity_number' => $this->identityNumber($student)]),
                'admission_letter_documents' => $this->documents->file($student, 'admission_offer', [
                    'admission_date' => $admission, 'ga_period' => $currentEnd->copy()->subYear()->addDay()->format('j M Y').' – '.$currentEnd->format('j M Y'),
                ]),
                'supervisor_letter_documents' => $this->documents->file($student, 'supervisor_letter', [
                    'subject' => 'Support for Graduate Assistantship Extension',
                    'body' => "I support the request to extend the Graduate Assistantship by {$months} months, from {$start->format('j F Y')}.",
                ]),
                default => $this->documents->file($student, 'supervisor_letter'),
            }];
        }

        // The Stage Gate proof this student is due for at the extension's start.
        $gate = collect($rules['stage_gates'][$level])->first(fn ($g) => $g['proof'] !== null);
        $files[$gate['proof']] = [$this->documents->file($student, 'acceptance_proof', $paper)];

        $this->http->post($student, route('ga-extension.store'), [
            'current_end_date' => $currentEnd->toDateString(),
            'requested_new_end_date' => $start->copy()->addMonthsNoOverflow($months)->subDay()->toDateString(),
            'reason_for_extension' => self::MARKER.' '.[
                'My data collection with the industry partner runs until mid-year, and the assistantship lets me keep supporting the undergraduate laboratory classes while I finish it.',
                'My second journal article is under review and I need six more months to complete the experiments for my final chapter while continuing my tutorial duties.',
                'The laboratory upgrade delayed my experiments by a semester; extending the GA lets me finish the remaining runs and keep teaching the lab sessions I lead.',
                'I am completing my thesis write-up and would like to continue as a Graduate Assistant for the coming semester, supporting the department\'s programming courses.',
            ][$i],
            'declaration' => '1',
            'mode_of_study' => 'full_time',
            'study_type' => 'research',
            'admission_date' => $admission->toDateString(),
            'candidature_period' => $admission->format('M Y').' – '.$candidacyEnd->format('M Y'),
            'level_of_study' => $level,
            'contact_no' => '+60 12-'.str_pad((string) (300 + $student->id % 700), 3, '0', STR_PAD_LEFT).' '.str_pad((string) (1000 + $student->id), 4, '0', STR_PAD_LEFT),
            'contact_email' => $student->email,
            'bank_name' => ['Maybank', 'CIMB Bank', 'Bank Islam', 'RHB Bank'][$i % 4],
            'bank_account_no' => '1140 '.str_pad((string) (2200 + $student->id), 4, '0', STR_PAD_LEFT).' 4455',
            'identity_number' => $this->identityNumber($student),
            'holds_visa' => '0',
            'publications' => [[
                'type' => 'journal', 'status' => 'accepted',
                'authors' => $student->name.', '.$student->supervisor->name,
                'year' => (string) now()->year,
                'title' => $paper['paper_title'], 'venue' => $paper['journal'],
                'database' => 'wos', 'quartile' => 'Q2',
            ]],
        ], $files);

        return $this->latest($student, 'ga_extension');
    }

    protected function returnGaExtension(Application $application): void
    {
        $this->advance($application, 'ga-extension', 'cgs_verify');

        $this->http->post($this->officer('ga_extension'), route('ga-extension.return', $application), [
            'to' => 'admin_verify',
            'remarks' => 'The admission letter uploaded is the offer letter only; please obtain the signed acceptance page from the student and verify it again.',
        ]);
    }

    protected function checkGaExtensionRules(Application $application): void
    {
        $detail = GaExtensionDetail::where('application_id', $application->id)->first();

        foreach (app(GaExtensionRules::class)->check($detail, $application->refresh(), $application->documents()->get()) as $check) {
            if ($check['status'] !== GaExtensionRules::PASS) {
                $this->warnings[] = "GA Extension {$application->reference()}: System check \"{$check['label']}\" is {$check['status']}: {$check['reason']}";
            }
        }
    }

    /**
     * The GA's current end date and the extension's start: far enough out
     * for the notice period (plus a week), ending on an allowed end day so
     * the next day is an allowed start day. Same rule as
     * NureenVideoDemoSeeder::extensionDates().
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function extensionDates(): array
    {
        $rules = config('nureen.ga_extension.rules');
        $end = today()->addMonthsNoOverflow((int) $rules['notice_months'])->addWeek();

        for ($i = 0; $i < 62 && ! ($this->dayAllowed($end, $rules['end_days']) && $this->dayAllowed($end->copy()->addDay(), $rules['start_days'])); $i++) {
            $end->addDay();
        }

        return [$end, $end->copy()->addDay()];
    }

    protected function dayAllowed(CarbonInterface $date, array $days): bool
    {
        foreach ($days as $day) {
            if ($day === 'last' ? $date->isLastOfMonth() : $date->day === (int) $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * MSc or PhD from the account's programme, and an admission date that
     * puts the student exactly at the first Stage Gate that asks for a proof
     * (Level 2) when $at comes -- so that check shows a real document.
     *
     * @return array{0: string, 1: Carbon}
     */
    protected function studyProfile(User $student, Carbon $at): array
    {
        $level = str_starts_with(strtolower((string) $student->programme), 'phd') ? 'phd' : 'msc';
        $gate = collect(config("nureen.ga_extension.rules.stage_gates.{$level}"))->first(fn ($g) => $g['proof'] !== null);

        return [$level, $at->copy()->subMonthsNoOverflow($gate['from'])];
    }

    /* ---------------------------------------------------------------
     | Supervision  (Supervisor -> Chair -> CGS)
     |---------------------------------------------------------------*/

    protected function supervisions(): void
    {
        $students = $this->take(4, 'Supervision', fn (User $s) => $this->staffOrNull(Role::CHAIR, $s->department) !== null);

        $titles = [
            'Federated Learning for Privacy-Preserving Medical Image Classification',
            'Digital Twin Modelling of Offshore Gas Compressors for Predictive Maintenance',
            'Graph Neural Networks for Detecting Fraud in Mobile Payment Networks',
            'Low-Power Edge AI for Real-Time Pipeline Leak Detection',
        ];

        foreach ($students as $i => $student) {
            $this->http->post($student, route('supervision.store'), [
                'requested_supervisor_id' => (string) $student->supervisor_id,
                'appointment_role' => SupervisionDetail::ROLE_MAIN,
                'appointment_type' => 'existing',
                'justification' => self::MARKER." I have worked with {$student->supervisor->name} since admission on \"{$titles[$i]}\" and need the formal Main Supervisor appointment letter for my candidature file.",
            ], [
                'supporting_documents' => [$this->documents->file($student, 'research_proposal', ['title' => $titles[$i]])],
            ]);

            $application = $this->latest($student, 'supervision');

            match ($i) {
                // 1. Waiting at the supervisor.
                0 => null,
                // 2. Endorsed by the supervisor: waiting at the Chair.
                1 => $this->advance($application, 'supervision', 'chair'),
                // 3. Rejected by the supervisor, with a reason.
                2 => $this->decide($application, 'supervision', 'reject',
                    'The proposal summary does not yet match the scope we agreed at the last meeting. Please revise it with me and submit again.'),
                // 4. Endorsed by CGS: the appointment letter (CGS/SVAPPT/...) is issued.
                3 => $this->advance($application, 'supervision', null),
            };

            if ($i === 3 && ! SupervisionDetail::where('application_id', $application->id)->value('letter_reference')) {
                throw new DemoStepFailed("Supervision {$application->reference()} was approved but no appointment letter was issued.");
            }

            $this->results[] = [
                'module' => 'Supervision',
                'case' => ['Waiting at Supervisor', 'Waiting at Chair of Department', 'Rejected by Supervisor, with a reason', 'Approved, appointment letter issued'][$i],
                'application' => $application,
                'student' => $student,
            ];
        }
    }

    /* ---------------------------------------------------------------
     | GA/GRA Certification  (Supervisor -> CGS -> Senior Director)
     |---------------------------------------------------------------*/

    protected function certifications(): void
    {
        $students = $this->take(4, 'GA/GRA Certification', fn (User $s) => true,
            fn (User $s) => str_contains(strtolower((string) $s->programme), 'full'));

        $periodStart = today()->subMonthsNoOverflow(6)->startOfMonth();
        $periodEnd = today()->subMonthNoOverflow()->endOfMonth();

        foreach ($students as $i => $student) {
            $type = $i === 1 ? 'GRA' : 'GA';
            $level = str_starts_with(strtolower((string) $student->programme), 'phd') ? 'phd' : 'master';
            $semester = $periodStart->format('F Y');
            $typeFields = $type === 'GA'
                ? ['course_code' => ['TFB2023', 'TEB1043', 'TFB3133', 'TEB2053'][$i], 'course_name' => ['Data Structures and Algorithms', 'Object-Oriented Programming', 'Machine Learning', 'Database Systems'][$i],
                    'course_owner_name' => $student->supervisor->name, 'semester' => $semester]
                : ['project_title' => 'Smart Sensing for Carbon Capture Monitoring', 'project_leader_name' => $student->supervisor->name,
                    'semester' => $semester, 'grant_code' => 'YUTP-FRG 015LC0-'.(400 + $student->id % 100)];

            // The appointment on file that the certification check compares
            // with (see AppointmentVerifier): it reads "Verified".
            $record = GaAppointmentRecord::create([
                'student_id' => $student->id,
                'appointment_type' => $type,
                'period_start' => $periodStart->copy()->subMonthsNoOverflow(2),
                'period_end' => today()->addMonthsNoOverflow(4)->endOfMonth(),
            ]);
            DemoRecord::remember($record->getTable(), $record->id);

            $this->http->post($student, route('ga-certification.store'), [
                'appointment_type' => $type,
                'purpose' => ['Supporting document for a scholarship application', 'Application for the Yayasan UTP research grant', 'Supporting document for a conference travel grant', 'Supporting document for a job application'][$i],
                'declaration' => '1',
                'study_level' => 'research',
                'level_of_study' => $level,
                'mode_of_study' => 'full_time',
                'identity_number' => $this->identityNumber($student),
                'personal_email' => Str::before($student->email, '@').'.personal@example.com',
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
            ] + $typeFields, [
                'supporting_documents' => [$this->documents->file($student, 'evidence_of_work', [
                    'type' => $type,
                    'semester' => $semester,
                    'course' => $type === 'GA' ? "{$typeFields['course_code']} {$typeFields['course_name']}" : $typeFields['project_title'],
                    'period' => $periodStart->format('j M Y').' – '.$periodEnd->format('j M Y'),
                    'signed_by' => $student->supervisor->name,
                ])],
            ]);

            $application = $this->latest($student, 'ga_certification');

            match ($i) {
                // 1. Endorsed by the supervisor: waiting at CGS, appointment "Verified".
                0 => $this->advance($application, 'ga-certification', 'cgs_verify'),
                // 2. Verified by CGS: waiting at the Senior Director.
                1 => $this->advance($application, 'ga-certification', 'senior_director'),
                // 3. Rejected by CGS, with a reason.
                2 => $this->rejectAtCgs($application),
                // 4. Approved: letter CGS/GACERT/... issued and publicly verifiable.
                3 => $this->advance($application, 'ga-certification', null),
            };

            if ($i === 3) {
                $reference = GaCertificationDetail::where('application_id', $application->id)->value('reference_number');

                if (! $reference || ! str_starts_with($reference, config('nureen.ga_certification.reference_prefix').'/')) {
                    throw new DemoStepFailed("Certification {$application->reference()} was approved without a CGS/GACERT reference.");
                }

                // The public page the letter's QR code opens, with no login.
                $this->http->get(null, route('certificate.verify', $reference));
            }

            $this->results[] = [
                'module' => 'GA/GRA Certification',
                'case' => ['Waiting at CGS, appointment Verified', 'Waiting at Senior Director', 'Rejected by CGS, with a reason', 'Approved, letter issued and verifiable'][$i],
                'application' => $application,
                'student' => $student,
            ];
        }
    }

    protected function rejectAtCgs(Application $application): void
    {
        $this->advance($application, 'ga-certification', 'cgs_verify');
        $this->decide($application, 'ga-certification', 'reject',
            'The Evidence of Work Done is not signed by the Course Owner. Please have it signed and submit a new request.');
    }

    /* ---------------------------------------------------------------
     | Attendance  (weeks 1-6 of 14, weekly periods)
     |---------------------------------------------------------------*/

    protected function attendance(): void
    {
        $students = $this->take(4, 'Attendance', fn (User $s) => true,
            fn (User $s) => str_contains(strtolower((string) $s->programme), 'full'));
        $weeks = $this->weekEnds(6);
        $series = array_values(self::ATTENDANCE);

        // The same file CGS would upload (AttendanceSheet::COLUMNS), read and
        // ingested by the same classes the upload uses.
        $lines = [implode(',', AttendanceSheet::COLUMNS)];
        foreach ($students as $i => $student) {
            foreach ($weeks as $w => $end) {
                $lines[] = implode(',', [$student->matric_no, $end->toDateString(), $series[$i][$w], self::SESSIONS_PER_WEEK]);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'nureen-demo-utrace');
        file_put_contents($path, implode("\n", $lines)."\n");

        try {
            $parsed = AttendanceSheet::readFile(new UploadedFile($path, 'utrace-weeks-1-6.csv', 'text/csv', null, true));
        } finally {
            @unlink($path);
        }

        if ($parsed['error']) {
            throw new DemoStepFailed("Attendance sheet rejected: {$parsed['error']}");
        }

        $summary = (new AttendanceIngestor)->ingest(collect($parsed['rows']));
        $ids = $students->pluck('id')->all();
        DemoRecord::remember((new AttendanceRecord)->getTable(), AttendanceRecord::whereIn('student_id', $ids)->pluck('id'));

        if ($summary->rejected > 0) {
            throw new DemoStepFailed('Attendance rows rejected: '.implode(' ', $summary->reasons));
        }

        // The normal risk check and early-warning emails, for these four
        // students only: an upload runs it for everyone, which would also
        // re-evaluate (and possibly email) students this command did not touch.
        app(AttendanceEarlyWarning::class)->run($ids);
        DemoRecord::remember((new AttendanceRiskState)->getTable(), AttendanceRiskState::whereIn('student_id', $ids)->pluck('id'));
        DemoRecord::remember((new AttendanceAlert)->getTable(), AttendanceAlert::whereIn('student_id', $ids)->pluck('id'));

        $expected = ['good' => null, 'projected' => 'projected', 'rule' => 'current', 'appeal' => 'both'];

        foreach ($students as $i => $student) {
            $key = array_keys(self::ATTENDANCE)[$i];
            $state = AttendanceRiskState::where('student_id', $student->id)->first();

            if (($state?->reason) !== $expected[$key]) {
                $this->warnings[] = "Attendance ({$key}) {$student->email}: expected risk reason ".($expected[$key] ?? 'none').', got '.($state?->reason ?? 'none').'.';
            }

            $application = null;

            if ($key === 'appeal') {
                $application = $this->attendanceAppeal($student, $weeks);
            }

            $figures = $state ? sprintf('%s%% so far, projected %s%%', $state->current_percentage, $state->projected_percentage ?? '—') : '';

            $this->results[] = [
                'module' => 'Attendance',
                'case' => [
                    'good' => 'Good: steady above 85%',
                    'projected' => 'At risk by PROJECTION only',
                    'rule' => 'At risk by the 80% RULE',
                    'appeal' => 'At risk, appeal waiting for CGS',
                ][$key],
                'application' => $application,
                'student' => $student,
                'stage' => $application ? null : ($state?->at_risk ? "At-Risk list ({$figures})" : "Not flagged ({$figures})"),
                'login' => $application ? null : ($state?->at_risk ? $this->officer('attendance_appeal')->email : $student->email),
            ];
        }
    }

    /** @param  array<int, Carbon>  $weeks */
    protected function attendanceAppeal(User $student, array $weeks): Application
    {
        $record = AttendanceRecord::where('student_id', $student->id)->orderByDesc('period_end')->firstOrFail();
        $period = $weeks[3]->copy()->subDays(4)->format('j M').' – '.end($weeks)->format('j M Y');

        $this->http->post($student, route('attendance-appeal.store'), [
            'attendance_record_id' => (string) $record->id,
            'sessions_requested' => (string) min(6, $record->sessionsMissed()),
            'reason' => self::MARKER.' In the last three weeks I was at the partner plant in Kerteh collecting field data with my supervisor, '
                .'where UTrace cannot record attendance. The justification letter and monthly activity breakdown are attached.',
        ], [
            'justification_letter_documents' => [$this->documents->file($student, 'justification_letter', [
                'period' => $period,
                'reason' => 'I was on approved fieldwork at the industry partner\'s plant in Kerteh, Terengganu, collecting data for my research, where check-in to UTrace was not possible.',
            ])],
            'activity_breakdown_documents' => [$this->documents->file($student, 'activity_breakdown', ['rows' => [
                [$weeks[3]->copy()->subDays(4)->format('j M Y'), 'Travel to Kerteh; site safety induction', 'PETRONAS Kerteh'],
                [$weeks[3]->format('j M Y'), 'Sensor installation on compressor train B', 'PETRONAS Kerteh'],
                [$weeks[4]->copy()->subDays(2)->format('j M Y'), 'Field data collection (shift 1 and 2)', 'PETRONAS Kerteh'],
                [$weeks[4]->format('j M Y'), 'Data verification with plant engineers', 'PETRONAS Kerteh'],
                [$weeks[5]->copy()->subDays(3)->format('j M Y'), 'Progress meeting with supervisor (online)', 'Kerteh / UTP'],
                [$weeks[5]->format('j M Y'), 'Return to campus; data upload to research server', 'UTP'],
            ]])],
        ]);

        return $this->latest($student, 'attendance_appeal');
    }

    /**
     * The Friday ending each of the semester's first $count weeks
     * (config nureen.attendance.semester_start, a Monday). Without a
     * configured start, the last $count completed weeks.
     *
     * @return array<int, Carbon>
     */
    protected function weekEnds(int $count): array
    {
        $start = config('nureen.attendance.semester_start');

        if ($start) {
            $start = Carbon::parse($start)->startOfDay();
            $firstFriday = $start->isFriday() ? $start : $start->copy()->next(Carbon::FRIDAY);
        } else {
            $firstFriday = (today()->isFriday() ? today() : today()->previous(Carbon::FRIDAY))->subWeeks($count - 1);
        }

        $weeks = array_map(fn (int $w) => $firstFriday->copy()->addWeeks($w), range(0, $count - 1));

        if (end($weeks)->gt(today())) {
            throw new DemoStepFailed("Week {$count} of the semester (ending ".end($weeks)->format('j M Y').') has not ended yet, so it cannot be recorded. Check NUREEN_ATTENDANCE_SEMESTER_START.');
        }

        return $weeks;
    }

    /* ---------------------------------------------------------------
     | Moving applications along
     |---------------------------------------------------------------*/

    /**
     * Approves at each stage, as whoever holds it, until the application
     * reaches $stopAt (a stage key) -- or, with null, until it is decided.
     */
    protected function advance(Application $application, string $routePrefix, ?string $stopAt): Application
    {
        for ($i = 0; $i < 10; $i++) {
            $application->refresh();

            if ($application->status !== Application::STATUS_PENDING || $application->current_stage === $stopAt) {
                break;
            }

            $this->decide($application, $routePrefix, 'approve');
        }

        if ($stopAt !== null && ($application->status !== Application::STATUS_PENDING || $application->current_stage !== $stopAt)) {
            throw new DemoStepFailed("{$application->reference()} did not stop at '{$stopAt}' (it is {$application->status} at {$application->current_stage}).");
        }

        return $application;
    }

    protected function decide(Application $application, string $routePrefix, string $decision, ?string $remarks = null): Application
    {
        $stage = $this->engine->currentStage($application->refresh())
            ?? throw new DemoStepFailed("{$application->reference()} is not waiting for a decision.");

        $this->http->post($this->actorFor($application, $stage), route("{$routePrefix}.decide", $application),
            array_filter(['decision' => $decision, 'remarks' => $remarks]));

        return $application->refresh();
    }

    /** Whoever can act on $application at $stage, as the module's own queue decides it. */
    protected function actorFor(Application $application, Stage $stage): User
    {
        $student = $application->student;

        return match (true) {
            $stage->role === Role::SUPERVISOR => User::findOrFail(match ($application->module_type) {
                'supervision' => SupervisionDetail::where('application_id', $application->id)->value('requested_supervisor_id'),
                'ga_extension' => GaExtensionDetail::where('application_id', $application->id)->value('supervisor_id'),
                'ga_certification' => GaCertificationDetail::where('application_id', $application->id)->value('supervisor_id'),
                default => $student->supervisor_id,
            }),
            $stage->role === Role::NON_EXEC_CGS => $this->officer($application->module_type),
            $stage->role === HaziqRoles::RESEARCH_CENTRE => $this->researchCentre($student) ?? $this->staff($stage->role),
            Role::isDepartmentScoped($stage->role) => $this->staff($stage->role, $student->department),
            default => $this->staff($stage->role),
        };
    }

    /* ---------------------------------------------------------------
     | People
     |---------------------------------------------------------------*/

    /**
     * Existing student accounts with nothing on file anywhere this command
     * writes, and a supervisor who can act -- Computing first, so the demo
     * runs through the familiar supervisor@, chair@ and researchcentre@;
     * the Foundation centres last.
     *
     * @return Collection<int, User>
     */
    protected function freshStudents(): Collection
    {
        $untouched = [
            ['applications', 'student_id'],
            [(new AttendanceRecord)->getTable(), 'student_id'],
            [(new AttendanceRiskState)->getTable(), 'student_id'],
            [(new AttendanceAlert)->getTable(), 'student_id'],
            [(new GaAppointmentRecord)->getTable(), 'student_id'],
            [(new GraGaStudentProfile)->getTable(), 'user_id'],
            [(new TermsAcceptance)->getTable(), 'user_id'],
        ];

        $query = User::where('role', Role::STUDENT)
            ->whereNotNull('matric_no')
            ->whereNotNull('department')
            ->whereNotNull('supervisor_id')
            ->with('supervisor');

        foreach ($untouched as [$table, $column]) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn("{$table}.{$column}", 'users.id'));
        }

        return $query
            // Computing, then the faculty departments, the Foundation centres last.
            ->orderByRaw("case when department = 'Computing' then 0 when department like 'Foundation%' then 2 else 1 end")
            ->orderBy('id')
            ->get()
            ->filter(fn (User $s) => SupervisorCheck::problemFor($s) === null)
            ->values();
    }

    /**
     * $n students from the pool that $fits, preferring those $prefer
     * picks, each used once across the whole demo.
     *
     * @return Collection<int, User>
     */
    protected function take(int $n, string $for, callable $fits, ?callable $prefer = null): Collection
    {
        $fitting = $this->pool->filter($fits);
        $picked = $prefer
            ? $fitting->filter($prefer)->concat($fitting->reject($prefer))->take($n)
            : $fitting->take($n);

        if ($picked->count() < $n) {
            throw new DemoStepFailed("Only {$picked->count()} unused student account(s) can be used for {$for}; {$n} are needed. "
                .'The demo only uses students with no applications, attendance or GA/GRA records yet.');
        }

        $ids = $picked->pluck('id');
        $this->pool = $this->pool->reject(fn (User $s) => $ids->contains($s->id))->values();

        return $picked->values();
    }

    protected function staff(string $role, ?string $department = null): User
    {
        return $this->staffOrNull($role, $department)
            ?? throw new DemoStepFailed('No '.Role::label($role).' account'.($department ? " for {$department}" : '').' exists.');
    }

    protected function staffOrNull(string $role, ?string $department = null): ?User
    {
        return User::where('role', $role)
            ->when($department, fn ($q) => $q->where('department', $department))
            ->orderBy('id')
            ->first();
    }

    protected function researchCentre(User $student): ?User
    {
        return $this->staffOrNull(HaziqRoles::RESEARCH_CENTRE, $student->department);
    }

    protected function officer(string $moduleKey): User
    {
        return $this->assignment->officer($moduleKey) ?? $this->staff(Role::NON_EXEC_CGS);
    }

    /* ---------------------------------------------------------------
     | Small helpers
     |---------------------------------------------------------------*/

    /** The application the last request created, remembered as demo data. */
    protected function latest(User $student, string $moduleType): Application
    {
        $application = Application::where('student_id', $student->id)
            ->where('module_type', $moduleType)
            ->latest('id')
            ->firstOrFail();

        DemoRecord::remember('applications', $application->id);

        return $application;
    }

    protected function rememberWhere(\Illuminate\Database\Eloquent\Model $model, string $column, int $value): void
    {
        DemoRecord::remember($model->getTable(), $model->newQuery()->where($column, $value)->pluck('id'));
    }

    /** A MyKad-shaped number, different per student. */
    protected function identityNumber(User $student): string
    {
        $id = $student->id;

        return sprintf('%02d%02d%02d-%02d-%04d', [98, 99, 0, 1][$id % 4], 1 + $id % 12, 1 + $id % 28, [8, 10, 14, 1][$id % 4], 1000 + $id % 9000);
    }

    /** @return array{paper_title: string, journal: string, manuscript: string, indexing: string} */
    protected function paper(int $i): array
    {
        return [
            ['paper_title' => 'Lightweight Anomaly Detection for Campus IoT Networks', 'journal' => 'IEEE Access', 'manuscript' => 'ACCESS-2026-11873', 'indexing' => 'Web of Science (SCIE), Q2'],
            ['paper_title' => 'Transformer Models for Short-Term Load Forecasting in Malaysian Grids', 'journal' => 'Energy Reports', 'manuscript' => 'EGYR-D-26-02144', 'indexing' => 'Web of Science (SCIE), Q2'],
            ['paper_title' => 'Explainable Deep Learning for Corrosion Detection in Offshore Pipelines', 'journal' => 'Applied Sciences', 'manuscript' => 'applsci-3311907', 'indexing' => 'Web of Science (SCIE), Q2'],
            ['paper_title' => 'A Comparative Study of Query Optimisation in Distributed SQL Engines', 'journal' => 'Journal of King Saud University – Computer and Information Sciences', 'manuscript' => 'JKSUCIS-D-26-00981', 'indexing' => 'Web of Science (SCIE), Q2'],
        ][$i % 4];
    }

    /** @return array<int, array<int, string>> Module, Reference, Student, Current stage, Log in next as */
    protected function rows(): array
    {
        return array_map(function (array $r) {
            $application = $r['application']?->refresh();

            if (! $application) {
                return [$r['module'], '—', "{$r['student']->name} ({$r['student']->email})", "{$r['case']}: {$r['stage']}", $r['login']];
            }

            $stage = $this->engine->currentStage($application);
            $where = match ($application->status) {
                Application::STATUS_PENDING => "Waiting at {$stage?->label}",
                Application::STATUS_APPROVED => 'Approved',
                Application::STATUS_REJECTED => 'Rejected',
                default => Str::headline($application->status),
            };

            return [
                $r['module'],
                $application->reference(),
                "{$r['student']->name} ({$r['student']->email})",
                "{$r['case']} [{$where}]",
                $stage ? $this->actorFor($application, $stage)->email : $r['student']->email,
            ];
        }, $this->results);
    }
}
