<?php

namespace App\Modules\Norhanis\Http\Controllers;

use App\Modules\Core\Http\Controllers\Concerns\ApprovesApplications;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Services\WorkflowEngine;
use App\Modules\Norhanis\Models\ClaimsDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClaimsController extends Controller
{
    use ApprovesApplications;

    protected function moduleKey(): string
    {
        return 'claims_student';
    }

    public function create()
    {
        return view('norhanis::claims.form');
    }

    public function store(Request $request, WorkflowEngine $engine, DocumentStore $documents)
    {
        $data = $request->validate([
            'purpose_of_claim' => ['required', 'string', 'max:255'],
            'bank_account_no' => ['required', 'string', 'max:30'],
            'less_cash_advance' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_date' => ['required', 'date'],
            'items.*.travel_from' => ['nullable', 'string', 'max:150'],
            'items.*.travel_to' => ['nullable', 'string', 'max:150'],
            'items.*.flight_train_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.meal_allowance' => ['nullable', 'numeric', 'min:0'],
            'items.*.lodging_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.misc_amount' => ['nullable', 'numeric', 'min:0'],
            'receipt' => DocumentStore::rules(),
        ], [
            'items.required' => 'Add at least one expense item.',
        ]);

        $application = DB::transaction(function () use ($request, $data, $engine, $documents) {
            $application = Application::create([
                'student_id' => $request->user()->id,
                'module_type' => $this->moduleKey(),
                'status' => Application::STATUS_DRAFT,
            ]);

            // total_claim_amount and claim_balance are NEVER read from the
            // request — summed from the items array server-side, the same
            // way TravelController derives duration_days instead of trusting
            // a posted number. A student editing the DOM to inflate the
            // total client-side has no effect on what actually gets saved.
            $totalClaimAmount = collect($data['items'])->sum(function ($item) {
                return ($item['flight_train_amount'] ?? 0)
                    + ($item['meal_allowance'] ?? 0)
                    + ($item['lodging_amount'] ?? 0)
                    + ($item['misc_amount'] ?? 0);
            });
            $lessCashAdvance = $data['less_cash_advance'] ?? 0;

            $claimsDetail = ClaimsDetail::create([
                'application_id' => $application->id,
                'purpose_of_claim' => $data['purpose_of_claim'],
                'bank_account_no' => $data['bank_account_no'],
                'total_claim_amount' => $totalClaimAmount,
                'less_cash_advance' => $lessCashAdvance,
                'claim_balance' => $totalClaimAmount - $lessCashAdvance,
            ]);

            foreach ($data['items'] as $item) {
                $claimsDetail->items()->create([
                    'item_date' => $item['item_date'],
                    'travel_from' => $item['travel_from'] ?? null,
                    'travel_to' => $item['travel_to'] ?? null,
                    'flight_train_amount' => $item['flight_train_amount'] ?? 0,
                    'meal_allowance' => $item['meal_allowance'] ?? 0,
                    'lodging_amount' => $item['lodging_amount'] ?? 0,
                    'misc_amount' => $item['misc_amount'] ?? 0,
                ]);
            }

            if ($request->hasFile('receipt')) {
                $documents->attach($application, $request->file('receipt'), 'Receipt');
            }

            // Hands the application to the first approver in the chain.
            return $engine->submit($application);
        });

        return redirect()
            ->route('applications.index')
            ->with('status', "Claims application #{$application->id} submitted. Your supervisor has been notified.");
    }

    public function queue(Request $request, WorkflowEngine $engine)
    {
        $queue = $this->queueFor($request, $engine, ['documents']);

        // One query for all claim rows, eager-loading items so the queue
        // view can list each claim's expense lines without an N+1 query.
        $details = ClaimsDetail::whereIn('application_id', $queue['applications']->pluck('id'))
            ->with('items')
            ->get()
            ->keyBy('application_id');

        return view('norhanis::claims.queue', $queue + ['details' => $details]);
    }
}