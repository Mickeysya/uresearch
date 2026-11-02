<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Norhanis\Http\Controllers\Concerns\ApprovesAndNotifies;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Support\ApproverNotifier;
use App\Modules\Norhanis\Models\ClaimsDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClaimsController extends Controller
{
    use ApprovesAndNotifies;

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

    /** PDF/JPG/PNG, 5 MB max -- tighter than the portal-wide DocumentStore default. */
    protected const FILE_RULES = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

    protected function moduleKey(): string
    {
        return 'claims_student';
    }

    public function create()
    {
        return view('norhanis::claims.form');
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents, ApproverNotifier $notifier)
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_account_no' => ['required', 'string', 'max:30'],

            // Claims are journal-publication claims only: there is no expense
            // breakdown to sum, so total_claims is taken directly from the
            // student. See the comment on ClaimsDetail::create() below for
            // why that is safe.
            'total_claims' => ['required', 'numeric', 'gt:0', 'max:50000', 'decimal:0,2'],

            'receipt' => [...self::FILE_RULES, 'required'],
            'proof_of_payment' => [...self::FILE_RULES, 'required'],
            'invoice' => [...self::FILE_RULES, 'required'],

            // Optional, not conditionally required -- the form tells
            // international students it applies to them; nothing here reads
            // an international flag back to enforce it.
            'passport' => [...self::FILE_RULES, 'nullable'],
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            // total_claims is a direct student input, not derived from a
            // breakdown — there is nothing here for a tampered DOM value to
            // inflate, because the figure saved IS what the student typed.
            // The trust boundary moves downstream instead: each approver
            // checks this amount against the receipt, proof of payment and
            // invoice attached below before endorsing, the same way a paper
            // claim form is checked against its receipt.
            ClaimsDetail::create([
                'application_id' => $application->id,
                'bank_name' => $data['bank_name'],
                'bank_account_no' => $data['bank_account_no'],
                'total_claims' => $data['total_claims'],
            ]);

            foreach (self::DOCUMENT_FIELDS as $field => $label) {
                if ($request->hasFile($field)) {
                    $documents->attach($application, $request->file($field), $label);
                }
            }

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

        $details = ClaimsDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->get()
            ->keyBy('application_id');

        return view('norhanis::claims.queue', $queue + ['details' => $details]);
    }
}
