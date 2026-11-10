<?php

namespace App\Modules\Haziq\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentStore;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Exports\GaAllowanceEligibilityExport;
use App\Modules\Haziq\Models\GaAllowancePaymentLog;
use App\Modules\Haziq\Notifications\GaAllowancePaymentConfirmed;
use App\Modules\Haziq\Support\GaAllowanceEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * GA Allowance Eligibility -- GA's own reporting view, deliberately
 * separate from GRA's AllowanceController (own nav entry, own export,
 * own table), confirmed explicitly 2026-11-10: "ga ni letak actions
 * lain. jangan sekali kan dengan allowance gra satu excel. navigation
 * bar asing. with different names." Much simpler than GRA's: no Stage
 * Gates, no termination, nothing accumulates -- see GaAllowanceEngine's
 * own docblock for the cycle rule.
 */
class GaAllowanceController extends Controller
{
    public function index(Request $request, GaAllowanceEngine $engine)
    {
        $user = $request->user();
        abort_unless($user->isStudent() || $user->role === Role::SUPERVISOR || $user->isAdmin() || $user->isCgs(), 403);

        $month = $request->filled('month')
            ? Carbon::parse($request->query('month'))->startOfMonth()
            : Carbon::today()->startOfMonth();

        $serviceMonth = $engine->serviceMonthFor($month);

        $applications = Application::where('module_type', 'ga_application')
            ->where('status', Application::STATUS_APPROVED)
            ->when($user->isStudent(), fn ($q) => $q->where('student_id', $user->id))
            ->when($user->role === Role::SUPERVISOR, fn ($q) => $q->whereHas('student', fn ($s) => $s->where('supervisor_id', $user->id)))
            ->with('student')
            ->get();

        $applications->each(fn (Application $application) => $engine->ensureMonthlyLogs($application));

        $rows = GaAllowancePaymentLog::whereIn('application_id', $applications->pluck('id'))
            ->where('payment_month', $serviceMonth)
            ->with(['student', 'application'])
            ->get()
            ->map(fn (GaAllowancePaymentLog $log) => $this->rowFor($log));

        return view('haziq::ga_allowance.index', [
            'module' => 'ga',
            'month' => $month,
            'serviceMonth' => $serviceMonth,
            'rows' => $rows,
            'canExport' => $user->isAdmin() || $user->role === Role::SUPERVISOR || $user->isCgs(),
            'canConfirmPayment' => $user->isAdmin() || $user->role === Role::NON_EXEC_CGS,
        ]);
    }

    public function export(Request $request, GaAllowanceEngine $engine)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->role === Role::SUPERVISOR || $request->user()->isCgs(), 403);

        $month = $request->filled('month')
            ? Carbon::parse($request->query('month'))->startOfMonth()
            : Carbon::today()->startOfMonth();

        $serviceMonth = $engine->serviceMonthFor($month);

        $applications = Application::where('module_type', 'ga_application')
            ->where('status', Application::STATUS_APPROVED)
            ->get();
        $applications->each(fn (Application $application) => $engine->ensureMonthlyLogs($application));

        $rows = GaAllowancePaymentLog::whereIn('application_id', $applications->pluck('id'))
            ->where('payment_month', $serviceMonth)
            ->with(['student', 'application'])
            ->get()
            ->map(fn (GaAllowancePaymentLog $log) => $this->rowFor($log));

        return Excel::download(
            new GaAllowanceEligibilityExport($rows),
            "ga-allowance-eligibility-{$month->format('Y-m')}.xlsx"
        );
    }

    public function confirmPayment(Request $request, GaAllowancePaymentLog $log, DocumentStore $documents): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->role === Role::NON_EXEC_CGS, 403);
        abort_if($log->isConfirmed(), 404, 'This month has already been marked complete.');

        $request->validate(['proof' => DocumentStore::rules(true)]);

        $document = $documents->attach($log->application, $request->file('proof'), 'Proof of Payment');

        $log->update([
            'proof_document_id' => $document->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        $log->loadMissing('application.student.supervisor');
        $log->application->student?->notify(new GaAllowancePaymentConfirmed($log, forStudent: true));
        $this->paymentStakeholders($log->application)->each(
            fn (User $u) => $u->notify(new GaAllowancePaymentConfirmed($log, forStudent: false))
        );

        return back()->with('status', 'Payment marked complete. RM'.number_format((float) $log->amount, 2).' confirmed paid.');
    }

    /** @return array{student: User, application: Application, amount: float, confirmed: bool, confirmable: bool} */
    protected function rowFor(GaAllowancePaymentLog $log): array
    {
        return [
            'student' => $log->student,
            'application' => $log->application,
            'amount' => (float) $log->amount,
            'confirmed' => $log->isConfirmed(),
            'confirmable' => ! $log->isConfirmed(),
            'log' => $log,
        ];
    }

    protected function paymentStakeholders(Application $application): Collection
    {
        $recipients = User::where('role', Role::ADMIN)->get()
            ->merge(User::whereIn('role', Role::cgsTeam())->get());

        if ($supervisor = $application->student?->supervisor) {
            $recipients->push($supervisor);
        }

        return $recipients->unique('id');
    }
}
