<?php

namespace App\Modules\Hani\Database\Seeders;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Core\Support\Role;
use App\Modules\Hani\Jobs\RunSoftboundChecks;
use App\Modules\Hani\Models\Examiner;
use App\Modules\Hani\Models\ExaminerNomination;
use App\Modules\Hani\Models\VivaDetail;
use App\Modules\Hani\Models\VivaSoftboundCheck;
use App\Modules\Hani\Models\VivaSoftboundDetail;
use App\Modules\Hani\Notifications\VivaNominateExaminers;
use App\Modules\Hani\Support\Demo\FictionalPdfs;
use App\Modules\Hani\Support\Similarity\UploadedReportSource;
use App\Modules\Hani\Support\VivaChecklist;
use App\Modules\Hani\Support\VivaProgress;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The lecturer UAT for Examiner Nomination, ON DEMAND ONLY:
 *
 *     php artisan db:seed --class="App\Modules\Hani\Database\Seeders\ExaminerNominationUatSeeder"
 *
 * Nothing is typed live in the UAT, so everything the two screens need is
 * here, and every run puts it back to the same starting point (the UAT makes
 * real changes):
 *
 *   1. supervisor@utp.edu.my (Dr. Aisyah Rahman) has a candidate,
 *      student2@utp.edu.my (Nur Farah Adilah), whose Notice of Thesis
 *      Submission is filed, SV-endorsed and AE-verified, and no nomination
 *      yet: the next step is the supervisor's, on Nominate Examiners. The
 *      form then shows:
 *        - suggested examiners ranked by expertise (four internal and three
 *          external fictional examiners written for this thesis)
 *        - conflict-of-interest flags: an internal who co-authored with the
 *          supervisor, an external holding a UTP adjunct post
 *        - the cross-department duplicate flag: an external already on
 *          another department's pending nomination
 *        - an internal on the 90-day gap, greyed out "(On Gap)" in the list
 *   2. ae@utp.edu.my sees Nur Farah's softbound thesis in Theses to Check,
 *      with System checks that pass some items and warn on others. The checks
 *      are the real ones (RunSoftboundChecks), run on fictional PDFs.
 *
 * WHAT IT TOUCHES, and nothing else:
 *   - student2@'s viva_voce, viva_softbound, examiner_nomination and re_viva
 *     applications (Hani's own module types), deleted and rebuilt; her user
 *     row is never changed, nor any other module's records of hers
 *   - one fictional companion student and supervisor (example.com) and
 *     their nomination, for the duplicate flag
 *   - the eight fictional examiners below (example.com / example.org),
 *     restored to their stated state on every run
 *   - database notifications pointing at the deleted applications
 * It extends HaniDemoSeeder for its building blocks (notice(), at(),
 * academicExecFor(), backdate()), not to run the full demo.
 */
class ExaminerNominationUatSeeder extends HaniDemoSeeder
{
    public const SUPERVISOR = 'supervisor@utp.edu.my';

    public const STUDENT = 'student2@utp.edu.my';

    public const AE = 'ae@utp.edu.my';

    protected const MODULES = ['re_viva', VivaProgress::NOTICE, VivaProgress::SOFTBOUND, VivaProgress::NOMINATION];

    /** Nur Farah's notice: what the matcher reads. */
    protected const THESIS = [
        'thesis_title' => 'Privacy-Preserving Federated Deep Learning for Medical Image Classification Across Hospitals',
        'abstract' => 'Hospitals hold medical images that cannot leave their sites, which limits the data any one deep learning model can learn from. '
            .'This thesis proposes a federated learning framework in which convolutional neural networks are trained across hospitals without sharing patient images, '
            .'with differential privacy applied to the model updates. It evaluates the framework on chest X-ray and dermoscopy image classification, '
            .'and studies the trade-off between privacy budget, communication cost and diagnostic accuracy.',
        'keywords' => 'federated learning; differential privacy; medical image classification; convolutional neural networks; deep learning',
    ];

    /**
     * The fictional examiners, by email. `last_exam` is days ago (null: never
     * examined); GAP_DAYS is 90, so 35 days ago is On Gap.
     */
    protected const EXAMINERS = [
        // ---- Internal, Computing: the candidate's own department ----
        'hafizah.kamaruddin@example.com' => [
            'name' => 'Assoc Prof Dr Hafizah binti Kamaruddin', 'type' => 'internal', 'department' => 'Computing',
            'expertise' => 'Federated learning and privacy-preserving machine learning', 'last_exam' => 420,
            'keywords' => ['federated learning', 'differential privacy', 'distributed machine learning', 'data privacy'],
            'publications' => [['Communication-efficient federated learning with differential privacy', 2025, 'Hafizah Kamaruddin; Lim Jun Wei']],
        ],
        'haikal.hashim@example.com' => [
            'name' => 'Dr Mohd Haikal bin Hashim', 'type' => 'internal', 'department' => 'Computing',
            'expertise' => 'Medical image analysis', 'last_exam' => 200,
            'keywords' => ['medical image classification', 'medical imaging', 'convolutional neural networks', 'image segmentation'],
            'publications' => [],
        ],
        // Co-authored with the supervisor: the COI warning on an internal suggestion.
        'tan.meixuan@example.com' => [
            'name' => 'Dr Tan Mei Xuan', 'type' => 'internal', 'department' => 'Computing',
            'expertise' => 'Deep learning for computer vision', 'last_exam' => null,
            'keywords' => ['deep learning', 'convolutional neural networks', 'image classification', 'transfer learning'],
            'publications' => [['Transfer learning for chest X-ray image classification', 2024, 'Tan Mei Xuan; Aisyah Rahman']],
        ],
        // The closest fit of all, and examined 35 days ago: On Gap, so never
        // suggested and greyed out in the Internal Main list.
        'rahmat.ismail@example.com' => [
            'name' => 'Prof Dr Rahmat bin Ismail', 'type' => 'internal', 'department' => 'Computing',
            'expertise' => 'Privacy-preserving deep learning for healthcare', 'last_exam' => 35,
            'keywords' => ['federated learning', 'differential privacy', 'medical imaging', 'deep learning'],
            'publications' => [],
        ],
        // ---- External ----
        'ong.siewling@example.org' => [
            'name' => 'Prof Dr Ong Siew Ling', 'type' => 'external', 'department' => 'Faculty of Computer Science and Information Technology',
            'institution' => 'Universiti Malaya', 'years_experience' => 18,
            'expertise' => 'Federated learning and secure distributed AI', 'last_exam' => 300,
            'keywords' => ['federated learning', 'differential privacy', 'secure aggregation', 'deep learning'],
            'publications' => [],
        ],
        // Named on the companion's pending nomination: the duplicate (clash) flag.
        'gan.weijie@example.org' => [
            'name' => 'Assoc Prof Dr Gan Wei Jie', 'type' => 'external', 'department' => 'School of Computer Sciences',
            'institution' => 'Universiti Sains Malaysia', 'years_experience' => 12,
            'expertise' => 'Medical image computing', 'last_exam' => 250,
            'keywords' => ['medical image classification', 'convolutional neural networks', 'medical imaging', 'deep learning'],
            'publications' => [],
        ],
        // Holds a UTP adjunct post: the "not independent of UTP" COI warning.
        'kavitha.raman@example.org' => [
            'name' => 'Dr Kavitha a/p Raman', 'type' => 'external', 'department' => 'School of Information Technology',
            'institution' => 'Monash University Malaysia', 'years_experience' => 10,
            'expertise' => 'Trustworthy and privacy-preserving machine learning', 'last_exam' => 380,
            'current_utp_appointment' => 'Adjunct Lecturer (UTP)',
            'keywords' => ['differential privacy', 'federated learning', 'machine learning', 'healthcare analytics'],
            'publications' => [],
        ],
        // ---- The companion's internal main (Electrical), not a Computing match ----
        'azlan.mustafa@example.com' => [
            'name' => 'Dr Azlan bin Mustafa', 'type' => 'internal', 'department' => 'Electrical & Electronics Engineering',
            'expertise' => 'Power electronics', 'last_exam' => 500,
            'keywords' => ['power electronics', 'wide-bandgap devices', 'electric vehicle charging'],
            'publications' => [],
        ],
    ];

    /** The companion in another department whose pending list names gan.weijie. */
    protected const COMPANION = [
        'supervisor' => ['email' => 'roslan.yusof.uat@example.com', 'name' => 'Dr Roslan bin Yusof'],
        'student' => ['email' => 'aiman.rosli.uat@example.com', 'name' => 'Muhammad Aiman bin Rosli', 'matric' => '22009901', 'programme' => 'MSc Full-Time'],
        'department' => 'Electrical & Electronics Engineering',
        'thesis_title' => 'Wide-Bandgap Power Converters for Fast Electric Vehicle Charging',
        'internal_main' => 'azlan.mustafa@example.com',
        'external_main' => 'gan.weijie@example.org',
    ];

    protected ?Application $uatNotice = null;

    protected function steps(): array
    {
        return ['uatExaminers', 'uatReset', 'uatNotice', 'uatCompanion', 'uatSoftbound', 'uatSummary'];
    }

    protected function student(): User
    {
        $student = User::where('email', self::STUDENT)->where('role', Role::STUDENT)->first();

        if (! $student || $student->supervisor?->email !== self::SUPERVISOR) {
            throw new RuntimeException(self::STUDENT.' must exist and be supervised by '.self::SUPERVISOR.'. Run the main DatabaseSeeder first.');
        }

        return $student;
    }

    /** The fictional examiners, put back to their stated state. */
    protected function uatExaminers(): void
    {
        foreach (self::EXAMINERS as $email => $e) {
            $examiner = Examiner::updateOrCreate(['email' => $email], [
                'name' => $e['name'],
                'type' => $e['type'],
                'department' => $e['department'],
                'institution' => $e['institution'] ?? null,
                'years_experience' => $e['years_experience'] ?? null,
                'expertise' => $e['expertise'],
                'current_utp_appointment' => $e['current_utp_appointment'] ?? null,
                'former_utp_until' => null,
                'is_active' => true,
                'unavailable_reason' => null,
                'assigned_until' => null,
                'last_examination_date' => $e['last_exam'] === null ? null : today()->subDays($e['last_exam']),
            ]);

            $examiner->expertiseKeywords()->delete();
            foreach ($e['keywords'] as $keyword) {
                $examiner->expertiseKeywords()->create(['keyword' => $keyword]);
            }

            $examiner->publications()->delete();
            foreach ($e['publications'] as [$title, $year, $coauthors]) {
                $examiner->publications()->create(['title' => $title, 'year' => $year, 'coauthors' => $coauthors]);
            }
        }

        $this->command?->info('  UAT examiners: '.count(self::EXAMINERS).' fictional examiners restored.');
    }

    /**
     * Remove Nur Farah's and the companion's Hani applications (cascades take
     * the details, documents rows, history and nominations with them), and
     * the notifications that point at them.
     */
    protected function uatReset(): void
    {
        $ids = User::whereIn('email', [self::STUDENT, self::COMPANION['student']['email']])->pluck('id');

        $applications = Application::whereIn('student_id', $ids)->whereIn('module_type', self::MODULES)->get();
        $applicationIds = $applications->pluck('id')->all();

        if ($applicationIds !== []) {
            DB::table('notifications')->whereIn('data->application_id', $applicationIds)->delete();
        }

        $applications->each->delete();

        $this->command?->info('  Reset: '.count($applicationIds).' earlier viva / nomination / softbound record(s) removed.');
    }

    /** Filed 12 days ago, SV-endorsed the next day, AE-verified the day after. */
    protected function uatNotice(): void
    {
        $student = $this->student();

        $this->uatNotice = DB::transaction(fn () => $this->notice(self::THESIS, $student, acknowledge: true, filedDaysAgo: 12));
        $this->uatNotice->refresh();

        if ($this->uatNotice->status !== Application::STATUS_APPROVED) {
            throw new RuntimeException("The UAT notice #{$this->uatNotice->id} did not reach verified (status {$this->uatNotice->status}).");
        }

        // What the AE's Verified sends in real life: the supervisor's prompt
        // to nominate (bell and Mailpit). Sent now so the bell shows it.
        try {
            $student->supervisor->notifyNow(new VivaNominateExaminers(
                $this->uatNotice,
                self::THESIS['thesis_title'],
                $this->academicExecFor($student)->name,
            ));
        } catch (\Throwable $e) {
            $this->command?->warn('  Could not send the nominate-examiners notification: '.$e->getMessage());
        }
    }

    /** Another department's pending nomination naming gan.weijie: the duplicate flag. */
    protected function uatCompanion(): void
    {
        $c = self::COMPANION;

        $supervisor = User::updateOrCreate(['email' => $c['supervisor']['email']], [
            'name' => $c['supervisor']['name'], 'password' => 'password',
            'role' => Role::SUPERVISOR, 'department' => $c['department'],
        ]);
        $student = User::updateOrCreate(['email' => $c['student']['email']], [
            'name' => $c['student']['name'], 'password' => 'password', 'role' => Role::STUDENT,
            'matric_no' => $c['student']['matric'], 'programme' => $c['student']['programme'],
            'department' => $c['department'], 'supervisor_id' => $supervisor->id,
        ]);

        $examiner = fn (string $email) => Examiner::where('email', $email)->value('id');

        DB::transaction(function () use ($c, $student, $supervisor, $examiner) {
            $application = Application::create([
                'student_id' => $student->id,
                'submitted_by_id' => $supervisor->id,
                'module_type' => VivaProgress::NOMINATION,
                'status' => Application::STATUS_DRAFT,
            ]);
            ExaminerNomination::create([
                'application_id' => $application->id,
                'internal_main_id' => $examiner($c['internal_main']),
                'external_main_id' => $examiner($c['external_main']),
                'thesis_title' => $c['thesis_title'],
            ]);
            app(WorkflowEngine::class)->submit($application);
            $this->backdate($application, 3);
        });
    }

    /**
     * Nur Farah's softbound, filed two days ago and waiting for the AE. The
     * real checks run on fictional PDFs; the mix comes from what was filed:
     *   - declared 9% against an 11% report        -> similarity warning
     *   - a Digital Receipt 45 days old (limit 30) -> report-age warning
     *   - files readable, titles match, words match -> passes
     */
    protected function uatSoftbound(): void
    {
        $student = $this->student();
        $engine = app(WorkflowEngine::class);
        $documents = app(DocumentStore::class);
        $title = self::THESIS['thesis_title'];
        $words = 2400;

        $report = FictionalPdfs::turnitinReport($title, 11, [4, 3, 2], receipt: [
            'date' => today()->subDays(45)->format('d-M-Y').' 10:15AM (UTC+0800)', 'id' => '2471930562',
        ]);
        $reading = app(UploadedReportSource::class)->read($report);

        $attempt = DB::transaction(function () use ($student, $engine, $documents, $title, $words, $report, $reading) {
            $attempt = Application::create(['student_id' => $student->id, 'module_type' => VivaProgress::SOFTBOUND, 'status' => Application::STATUS_DRAFT]);
            VivaSoftboundDetail::create([
                'application_id' => $attempt->id,
                'viva_application_id' => $this->uatNotice->id,
                'field_of_study' => 'Federated Learning',
                'contact_number' => '012-345 6789',
                'personal_email' => 'nurfarah.personal@example.com',
                'session' => now()->format('F'),
                'year' => now()->year,
                'thesis_title' => $title,
                'similarity_percent' => 9,
                'similarity_source' => $reading->ok ? 'extracted' : 'self_declared',
                'similarity_extracted' => $reading->ok ? $reading->index : null,
                'word_count' => $words,
                'declaration_accepted' => true,
            ]);

            $documents->storeGenerated($attempt, FictionalPdfs::document('Thesis Format Confirmation Form (fictional)', ['Candidate: '.$student->name]), 'format-confirmation.pdf', 'Thesis Format Confirmation Form');
            $documents->storeGenerated($attempt, FictionalPdfs::thesis($title, $words, 4, $student->name), 'thesis.pdf', 'Softbound Thesis');
            $documents->storeGenerated($attempt, $report, 'turnitin-report.pdf', 'Turnitin Report');
            if (VivaChecklist::levelFor($student) === VivaChecklist::PHD) {
                $documents->storeGenerated($attempt, FictionalPdfs::document('RCS Correction Confirmation Form (fictional)'), 'rcs-confirmation.pdf', 'RCS Correction Confirmation Form');
                $documents->storeGenerated($attempt, FictionalPdfs::document('RCS Correction Summary (fictional)'), 'rcs-summary.pdf', 'RCS Correction Summary');
            }

            VivaSoftboundCheck::create(['application_id' => $attempt->id, 'status' => VivaSoftboundCheck::QUEUED, 'similarity' => $reading->toArray()]);
            $engine->submit($attempt);
            $this->backdate($attempt, 2);

            return $attempt;
        });

        // Synchronously, so the panel is complete before anyone logs in.
        app()->call([new RunSoftboundChecks($attempt->id), 'handle']);
    }

    protected function uatSummary(): void
    {
        $check = VivaSoftboundCheck::where('application_id', Application::where('student_id', $this->student()->id)
            ->where('module_type', VivaProgress::SOFTBOUND)->latest('id')->value('id'))->first();
        $counts = collect($check?->results ?? [])->countBy('status');

        $this->command?->info(sprintf('  Notice #%d (%s): verified; waiting for %s to nominate examiners.',
            $this->uatNotice->id, $this->uatNotice->reference(), self::SUPERVISOR));
        $this->command?->info(sprintf('  Softbound #%d: with %s; system checks %d pass, %d warning, %d not checked, %d info.',
            $check?->application_id, self::AE, $counts['pass'] ?? 0, $counts['warning'] ?? 0, $counts['not_checked'] ?? 0, $counts['info'] ?? 0));
    }
}
