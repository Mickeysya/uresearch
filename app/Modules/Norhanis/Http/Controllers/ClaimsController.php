<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Norhanis\Http\Controllers\Concerns\ApprovesAndNotifies;
use App\Modules\Norhanis\Http\Controllers\Concerns\SavesDrafts;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Support\ApproverNotifier;
use App\Modules\Norhanis\Support\ClaimablePublications;
use Illuminate\Validation\ValidationException;
use App\Modules\Core\Models\User;
use App\Modules\Norhanis\Models\ClaimsDetail;
use App\Modules\Norhanis\Models\PublicationDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ClaimsController extends Controller
{
    use ApprovesAndNotifies;
    use SavesDrafts;

    /**
     * The named upload fields on page 2, in display order, each attached
     * under its own label so approvers can tell them apart later. Passport
     * is still listed even when not required -- the loop only attaches a
     * field whose file was actually posted.
     *
     * @var array<string, string>
     */
    protected const DOCUMENT_FIELDS = [
        'receipt' => 'Receipt',
        'proof_of_payment' => 'Proof of Payment',
        'invoice' => 'Invoice',
        'passport' => 'Passport',
    ];

    /** PDF/JPG/PNG only (5 MB a file, as everywhere). */
    protected const FILE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    protected function moduleKey(): string
    {
        return 'claims_student';
    }

    protected function draftFields(): array
    {
        return ['publication_application_id', 'total_claims', 'bank_name', 'bank_account_no'];
    }

    protected function draftDocuments(): array
    {
        return self::DOCUMENT_FIELDS;
    }

    /**
     * Passport is optional: the form tells international students it
     * applies to them; nothing reads an international flag back to enforce it.
     */
    protected function optionalDocuments(): array
    {
        return ['passport'];
    }

    /** PDF/JPG/PNG -- narrower than the portal-wide list, which allows DOCX. */
    protected function documentExtensions(): ?array
    {
        return self::FILE_EXTENSIONS;
    }

    public function create(Request $request)
    {
        return $this->form($request, null);
    }

    protected function form(Request $request, ?Application $draft)
    {
        $publications = $this->approvedPublications($request->user());

        // ?publication={id}, from the "Start your claim" button: chosen for
        // the student when it is theirs to claim, quietly ignored otherwise
        // (the list below is the student's own, so nothing leaks).
        $asked = $request->query('publication');
        $preselected = is_scalar($asked) && $publications->contains('application_id', (int) $asked) ? (int) $asked : null;

        return view('norhanis::claims.form', [
            'publications' => $publications,
            'preselected' => $preselected,
            'ignoredLink' => $asked !== null && $preselected === null,
        ] + $this->draftViewData($request, $draft));
    }

    /**
     * The publications this student may still claim: see
     * Support\ClaimablePublications (own, finally approved, journal, Pay
     * and Claims, not already claimed). The same list validates store(), so
     * a claim can never name someone else's publication, a conference,
     * another cost centre, one still in approval or one already claimed --
     * even from an edited request.
     *
     * @return \Illuminate\Support\Collection<int, PublicationDetail>
     */
    protected function approvedPublications(User $student)
    {
        return app(ClaimablePublications::class)->for($student);
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents, ApproverNotifier $notifier)
    {
        $draft = $this->submittingDraft($request);
        $removing = $this->draftRemoving($request, $draft);

        $data = $this->validateWithDocuments($request, $draft, $removing, [
            'publication_application_id' => ['required', Rule::in($this->approvedPublications($request->user())->pluck('application_id'))],
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_account_no' => ['required', 'string', 'max:30'],

            // Claims are journal-publication claims only: there is no expense
            // breakdown to sum, so total_claims is taken directly from the
            // student. See the comment on ClaimsDetail::create() below for
            // why that is safe.
            'total_claims' => ['required', 'numeric', 'gt:0', 'max:50000', 'decimal:0,2'],

        ], [
            'publication_application_id.required' => 'Choose the publication this claim is for.',
            'publication_application_id.in' => 'Choose one of your own approved journal publications that used the Pay and Claims cost centre and has not been claimed yet.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents, $draft, $removing) {
            // Submitting a saved draft finishes that application rather than
            // starting a second one.
            // One claim per publication, checked again under a row lock so
            // two submits at once cannot both claim it.
            PublicationDetail::where('application_id', $data['publication_application_id'])->lockForUpdate()->first();
            if (! app(ClaimablePublications::class)->isClaimable($request->user(), $data['publication_application_id'])) {
                throw ValidationException::withMessages([
                    'publication_application_id' => 'This publication has already been claimed.',
                ]);
            }

            $application = $this->draftOrNewApplication($request, $draft, $removing);

            // total_claims is a direct student input, not derived from a
            // breakdown — there is nothing here for a tampered DOM value to
            // inflate, because the figure saved IS what the student typed.
            // The trust boundary moves downstream instead: each approver
            // checks this amount against the receipt, proof of payment and
            // invoice attached below before endorsing, the same way a paper
            // claim form is checked against its receipt.
            ClaimsDetail::create([
                'application_id' => $application->id,
                'publication_application_id' => $data['publication_application_id'],
                'bank_name' => $data['bank_name'],
                'bank_account_no' => $data['bank_account_no'],
                'total_claims' => $data['total_claims'],
            ]);

            $this->attachDraftFiles($request, $application, $documents);

            // Hands the application to the first approver in the chain.
            return $engine->submit($application);
        });

        $notifier->submitted($application);

        return redirect()
            ->route('applications.index')
            ->with('status', "Claims application #{$application->id} submitted. It is now waiting for your supervisor's decision.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        $details = ClaimsDetail::with('publication')
            ->whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('norhanis::claims.queue', $queue + ['details' => $details]);
    }
}
