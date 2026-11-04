<?php

namespace App\Modules\Haziq\Support;

use App\Modules\Core\Models\Application;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Role;
use App\Modules\Haziq\Models\AllowancePaymentConfirmation;
use App\Modules\Haziq\Models\AllowancePaymentLog;
use App\Modules\Haziq\Models\GaApplicationDetail;
use App\Modules\Haziq\Models\GraApplicationDetail;
use App\Modules\Haziq\Models\GraGaStudentProfile;
use App\Modules\Haziq\Models\StageGate;
use App\Modules\Haziq\Models\StageGateRule;
use App\Modules\Haziq\Models\StageGateTerminationTrigger;
use App\Modules\Haziq\Notifications\StageGateCompleted;
use App\Modules\Haziq\Notifications\StageGateGraceReminder;
use App\Modules\Haziq\Notifications\StageGateAllowanceWithheld;
use App\Modules\Haziq\Notifications\StageGateCandidatureTerminationTriggered;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything described in docs/scope/haziq.md's "Stage Gate Monitoring"
 * table, plus the allowance withholding/back-pay it drives.
 *
 * Two different kinds of "the date changed" are handled on purpose
 * differently:
 *
 *  - Time simply passing (the daily stage-gate:evaluate job, evaluateAll())
 *    never rewrites a deadline or grace end once a gate has had anything
 *    happen to it — completed, withheld, forfeited — and never lets CGS
 *    acting late retroactively erase a consequence. resyncIfUntouched()
 *    only resyncs a gate nothing has happened to yet (not_recorded +
 *    eligible), so a correction reaches a schedule that hadn't done
 *    anything with its old dates before anything real depended on them.
 *
 *  - A student correcting their own service_start_date (storeCorrection())
 *    is different: the old consequence was only ever true because of a
 *    wrong date. recomputeApplication() fully recomputes every open gate
 *    from the corrected date, including ones already withheld or
 *    forfeited — an honest fix is believed, not frozen out. Confirmed
 *    explicitly 2026-10-15, after resyncIfUntouched()'s freeze guard
 *    correctly-by-the-old-design but wrongly-in-practice left an edited
 *    application still showing withheld/terminated against its new date.
 */
class StageGateEngine
{
    /**
     * Called once, right after a GRA/GA application reaches approved status.
     * Builds the student's dated milestone schedule from stage_gate_rules +
     * this appointment's own service_start_date (GraApplicationDetail /
     * GaApplicationDetail — not the profile's admission_date, which is a
     * different, academic-candidature date that doesn't move with each new
     * appointment). Never called again for this application — an extension
     * keeps the original schedule, it does not regenerate one.
     */
    public function generateSchedule(Application $application, GraGaStudentProfile $profile, Carbon $startDate): void
    {
        if (StageGate::where('application_id', $application->id)->exists()) {
            return;
        }

        $rules = StageGateRule::forLevel($profile->level_of_study);

        foreach ($rules as $rule) {
            StageGate::create([
                'application_id' => $application->id,
                'rule_id' => $rule->id,
                'stage_order' => $rule->stage_order,
                'stage_name' => $rule->stage_name,
                'requirement_description' => $rule->requirement_description,
                'deadline_date' => $startDate->copy()->addMonths($rule->deadline_month),
                'grace_end_date' => $startDate->copy()->addMonths($rule->grace_end_month),
            ]);
        }
    }

    /**
     * The daily `stage-gate:evaluate` job. One pass over every open stage
     * gate belonging to an approved application.
     */
    public function evaluateAll(): void
    {
        StageGate::query()
            ->whereHas('application', fn ($q) => $q->where('status', Application::STATUS_APPROVED))
            ->where('completion_status', '!=', 'completed')
            ->with('application')
            ->each(fn (StageGate $gate) => $this->evaluateGate($gate));

        // Backfills every month up to and including this one, not just
        // today's — if the job missed a run (or an application only just
        // caught up), a gap here would mean a withheld month with no log
        // row to ever back-pay against.
        Application::query()
            ->where('status', Application::STATUS_APPROVED)
            ->whereIn('module_type', ['gra_application', 'ga_application'])
            ->each(fn (Application $application) => $this->ensureMonthlyLogs($application));
    }

    /**
     * Called synchronously right after a student's correction actually
     * changes service_start_date (see GraApplicationController::
     * storeCorrection()), so the Stage Gate schedule actually reflects the
     * correction immediately instead of silently waiting for the next
     * daily stage-gate:evaluate run. Confirmed explicitly 2026-10-15:
     * "die kene linked" — the edit and the schedule need to be connected,
     * not two things that happen to agree once a day. Unlike evaluateAll()
     * / evaluateGate()'s own resyncIfUntouched() guard, this does NOT
     * freeze out a gate that's already withheld/forfeited — every gate's
     * deadline_date/grace_end_date, completion_status, allowance_status
     * and timeline_status are reset from the corrected date and
     * re-evaluated from scratch, and candidature_status is reset to
     * active first. completion_status resets to not_recorded too — even
     * for an already-completed gate — confirmed explicitly 2026-10-15:
     * "kalau service date reset, grace period kn reset en... complete not
     * complete features kat each stage auto reset. cgs boleh mark balik."
     * A completion CGS already recorded was judged against the OLD
     * deadline; once that deadline moves, CGS re-makes the call against
     * the real one rather than the gate staying stuck "completed" against
     * a schedule that no longer applies. If the corrected date still
     * leaves a (now-reopened) gate overdue, evaluateGate() below
     * re-applies the consequence (and re-notifies — resetting
     * timeline_status clears the "already fired" guard); if it doesn't,
     * the gate genuinely goes back to eligible/active, same as before.
     * Any earlier StageGateTerminationTrigger row stays in the database
     * as history (see applyGraceExpiry()) — correcting the date doesn't
     * erase the record that a termination was once triggered, it just
     * stops that old, wrong trigger from dictating the *current* status.
     */
    public function recomputeApplication(Application $application, Carbon $newStartDate): void
    {
        if ($application->status !== Application::STATUS_APPROVED) {
            return;
        }

        // Wrapped in its own transaction — found 2026-11-03 via a real
        // 500 that this method's caller (GraApplicationController::
        // storeCorrection()) calls this *after* its own transaction has
        // already committed, with nothing wrapping this method itself.
        // A crash partway through (the amount NOT NULL bug this same
        // day) left application #25 with its Stage Gates reset and
        // terminated, but allowance_payment_logs only half-resynced —
        // a genuinely inconsistent state, not just a failed request.
        // This method writes across StageGate, GraApplicationDetail/
        // GaApplicationDetail, AllowancePaymentConfirmation and
        // AllowancePaymentLog; none of that is safe to leave partially
        // applied, so it all succeeds or all rolls back together now.
        DB::transaction(fn () => $this->doRecomputeApplication($application, $newStartDate));
    }

    protected function doRecomputeApplication(Application $application, Carbon $newStartDate): void
    {
        // Every gate, including ones CGS already marked complete/not
        // complete — confirmed explicitly 2026-10-15: "kalau service date
        // reset, grace period kn reset en... complete not complete
        // features kat each stage auto reset. cgs boleh mark balik." A
        // corrected service_start_date moves the deadline/grace dates a
        // completion was judged against, so that judgement goes back to
        // not_recorded too, for CGS to re-make against the real dates —
        // not left stuck "completed" against a schedule that no longer
        // applies.
        $gates = StageGate::where('application_id', $application->id)
            ->with(['application', 'rule'])
            ->get();

        foreach ($gates as $gate) {
            if (! $gate->rule) {
                continue;
            }

            $gate->deadline_date = $newStartDate->copy()->addMonths($gate->rule->deadline_month);
            $gate->grace_end_date = $newStartDate->copy()->addMonths($gate->rule->grace_end_month);
            $gate->completion_status = 'not_recorded';
            $gate->completion_date = null;
            $gate->completed_by = null;
            $gate->notes = null;
            $gate->allowance_status = 'eligible';
            $gate->timeline_status = 'upcoming';
            $gate->last_reminder_sent_at = null;
            $gate->save();
        }

        $this->terminateCandidature($application, terminated: false);

        // Confirmed explicitly 2026-10-23: "untuk satu student ni die
        // akan reset everything back kalau student tukar service start
        // date" — a payment confirmation was a real Admin/CGS action
        // against the OLD schedule's running total; once the schedule
        // itself changes, that total (and everything it was computed
        // from) is gone, so the confirmation no longer refers to
        // anything real either.
        AllowancePaymentConfirmation::where('application_id', $application->id)->delete();

        $gates->each(fn (StageGate $gate) => $this->evaluateGate($gate));

        // Includes 'backdated' rows here on purpose — the gates loop just
        // above already reset every gate's completion_status back to
        // not_recorded, so any 'backdated' row (a back-payment settled
        // against a completion that's just been reopened) is no longer a
        // real, standing payment event either; it has to be recomputed
        // like everything else. Confirmed explicitly 2026-10-23: a real
        // withheld-since-Nov-2025 gate, reopened by a later date
        // correction, still had an unrelated Oct-2026 row stuck
        // 'backdated' from an earlier (now-reset) completion — the table
        // showed a one-off settled amount instead of the real, still-
        // growing withheld total.
        $this->recomputeMonthlyLogs($application, $newStartDate, includeBackdated: true);
        $this->ensureMonthlyLogs($application);
    }

    /**
     * Existing AllowancePaymentLog rows were written under whatever
     * schedule was true at the time — a correction invalidates that.
     * Re-derives each row's governing gate and status from the
     * now-corrected schedule, the same statusAsOf() logic
     * ensureMonthlyLogs() uses for brand-new rows. Only ever called from
     * recomputeApplication() (a service_start_date correction, where the
     * user has confirmed everything resets) — never from a candidature
     * appeal approval, which must NOT rewrite history the same way; see
     * CandidatureAppealController::decide()'s own, much narrower
     * handling of that case.
     *
     * 'backdated' rows are left alone by default — that status only ever
     * comes from backPay(), a real CGS action restating a real payment
     * already made, not something this system computed on its own.
     * recomputeApplication() passes includeBackdated: true instead,
     * since by the time it calls this, every gate's completion_status
     * has already been reset — see its own call site for why a
     * 'backdated' row no longer gets to stay exempt in that specific
     * flow.
     *
     * Two things found missing 2026-11-03, tracing why a corrected row
     * still read RM0.00 after flipping back to 'eligible': this only
     * ever rewrote `status`/`stage_gate_id`, leaving `amount` stuck at
     * whatever an old schedule had computed (e.g. 0 from a since-undone
     * termination freeze) and any stray `pending_addition` riding along
     * untouched. Both are now recomputed/cleared alongside `status` —
     * same spirit as the AllowancePaymentConfirmation::delete() just
     * above in recomputeApplication(), which already treats a date
     * correction as invalidating the old financial state, not just the
     * old dates. Also now deletes any row dated before $newStartDate —
     * under the corrected schedule those months never happened, so
     * there's no sensible status/amount to recompute for them; before
     * this they just sat there permanently, re-dated against a schedule
     * that no longer applies.
     *
     * A third thing found the same day, on a real correction that also
     * re-triggered a termination: this used to flip *every* surviving
     * row to 'forfeited' the moment `candidature_status` read
     * 'terminated', the exact flat-boolean mistake
     * terminatedSinceFor()'s own docblock describes — a termination
     * that only actually started 10 months into this application's
     * history was blanket-applied back to month one. Now anchored the
     * same way ensureMonthlyLogs() already was. Deliberately simpler
     * than that method's own within-the-window handling, though:
     * ensureMonthlyLogs() distinguishes the exact entry month
     * ('review_required', a CGS judgement call) and a Stage-1-already-
     * complete freeze ('backdated', amount 0) from the months after,
     * both of which depend on sequential previous-row state. This just
     * marks the whole terminated window 'forfeited' — correct for
     * every month *after* the entry one, and ensureMonthlyLogs() never
     * overwrites an existing row, so if that finer distinction turns
     * out to matter for a *corrected* entry month too, it still needs
     * doing here.
     */
    protected function recomputeMonthlyLogs(Application $application, Carbon $newStartDate, bool $includeBackdated = false): void
    {
        $stage1 = $this->governingGate($application);
        $terminatedSince = $this->terminatedSinceFor($application);
        $amount = $this->monthlyAllowance($application);

        $query = AllowancePaymentLog::where('application_id', $application->id)
            ->when(! $includeBackdated, fn ($q) => $q->where('status', '!=', 'backdated'));

        (clone $query)->where('payment_month', '<', $newStartDate->copy()->startOfMonth())->delete();

        $query->where('payment_month', '>=', $newStartDate->copy()->startOfMonth())
            ->get()
            ->each(function (AllowancePaymentLog $log) use ($stage1, $terminatedSince, $amount) {
                $status = ($terminatedSince && $log->payment_month->gte($terminatedSince))
                    ? 'forfeited'
                    : $this->statusAsOf($stage1, $log->payment_month->copy()->endOfMonth());

                $log->update([
                    'stage_gate_id' => $stage1?->id,
                    'status' => $status,
                    // amount is NOT NULL on this column — 0.0 for
                    // 'forfeited', never null; rowFor()'s own forfeited
                    // branch always displays null regardless of what's
                    // stored here, so this is purely to satisfy the
                    // schema, not a display decision. Found 2026-11-03
                    // via a real 500: a correction that also terminates
                    // the candidature hit this write with null and
                    // violated the column's NOT NULL constraint.
                    'amount' => $status === 'forfeited' ? 0.0 : $amount,
                    'pending_addition' => null,
                ]);
            });
    }

    /**
     * Called from CandidatureAppealController::decide() the moment a
     * Senior Director approves an appeal. Confirmed explicitly
     * 2026-10-28: a termination is nothing like a withheld Stage 1 —
     * "bulan kene terminated takkan dapat allowance" (a terminated month
     * is never paid, there is no back-pay for it, ever) — so this never
     * touches any month before the one reinstatement happens in. Every
     * month before it stays 'forfeited' forever; every month after it is
     * handled the normal way, by the next evaluation, once
     * candidature_status itself already reads active.
     *
     * The reinstatement month itself resolves immediately, on its own —
     * simplified 2026-11-03, superseding two earlier versions from
     * earlier the same day (first a mandatory CGS-set 'review_required'
     * figure deferred to next month, then that same thing but only for
     * a day-other-than-the-1st, with the 1st resolving immediately).
     * User: "citer die skang apply to every situation... ni hanaya 1
     * haribulan" — generalising the 1st's own behaviour to every day
     * rather than keeping two different paths. There is no CGS judgement
     * call to make here any more regardless of what day the appeal lands
     * on: Stage 1's own current state decides it outright — red
     * (withheld) if still incomplete, green/amber (eligible/backdated)
     * if already complete — and whatever Stage 1 total was running right
     * before the termination gap folds straight back in
     * (AllowanceLedger::priorWithheldTotal(), shared with
     * AllowanceController so the two can never drift apart).
     * AllowanceController::setReviewAmount() still exists and is still
     * needed — but only for the *entry* side of a termination
     * (ensureMonthlyLogs()'s own 'review_required' for the month
     * termination first starts in), which this method has nothing to do
     * with.
     *
     * Refined twice more, same day, converging on one rule: whether
     * there's anything to *resume* (priorWithheldTotal() > 0 — Stage 1
     * was mid-accumulation, or complete but never confirmed-paid, right
     * before the termination) decides whether this month shows
     * anything at all, but this month's *own* contribution — whether
     * the 1st's full ordinary entitlement or a non-1st's CGS-set
     * partial figure — never shows live, same month, regardless. It
     * always goes through `pending_addition` and surfaces on the
     * *following* month's row (rowFor()'s withheld/backdated branch),
     * exactly the same payroll lag as everything else this module ever
     * defers. Confirmed explicitly 2026-11-03, catching this exact
     * mistake in the first "day 1 shows immediately" version: "bukan
     * patut keluar 14000 next month nye baru 16000" — the pre-existing
     * balance (14000) shows this month, but the newly-added portion
     * only shows up next month, on the 1st exactly as much as any
     * other day.
     *
     *  - Something to resume: the existing balance shows immediately,
     *    any day — "allowance tertunggak tu keluar... cgs boleh mark
     *    complete or not," a pre-existing balance needs no new payroll
     *    processing to just be visible again. This month's own
     *    contribution is queued into `pending_addition`: automatically,
     *    the full ordinary amount, on the 1st (no partial-month
     *    question to ask); manually, via
     *    AllowanceController::setReviewAmount(), any other day.
     *  - Nothing to resume: never shows this month either, on *any*
     *    day — corrected explicitly 2026-11-03, same day as the fix
     *    above: "1 hari bulan appeal pun keluar next monthnya" (even a
     *    1st-of-month appeal only shows up the month after). The 1st
     *    only removes the *partial-month question* — there's no CGS
     *    figure to ask for, so it's auto-determined using the ordinary
     *    monthly amount — but the payroll lag itself still applies, so
     *    this still writes 'determined' (blank this month) rather than
     *    resolving straight to a live status. Any other day is the
     *    same thing but CGS provides the figure manually
     *    (AllowanceController::setReviewAmount()) instead of it being
     *    automatic — blank now ('review_required'), CGS's figure (if
     *    any) only visible next month, exactly like the entry side
     *    already works.
     *
     * A Case A (tertunggak) row never shows 'forfeited' in the first
     * place — ensureMonthlyLogs() freezes it at 'backdated'/amount=0
     * instead, so it stays visible through the whole termination
     * (confirmed explicitly 2026-11-03: "bulan after termination takkan
     * accumulated... amik sampai [grace end] je"). It's always got
     * something to resume by definition (a frozen row only exists
     * because something was accumulated before the freeze), so it
     * always takes the "something to resume" branch above — shows the
     * frozen total outright, queues this month's own contribution into
     * `pending_addition` the same way Case B does.
     */
    public function resumeReinstatementMonth(Application $application): void
    {
        $today = Carbon::today();
        $month = $today->copy()->startOfMonth();

        $log = AllowancePaymentLog::where('application_id', $application->id)
            ->where('payment_month', $month)
            ->where(fn ($q) => $q->where('status', 'forfeited')
                ->orWhere(fn ($q2) => $q2->where('status', 'backdated')->where('amount', 0)))
            ->first();

        if (! $log) {
            return;
        }

        if ($log->status === 'backdated') {
            // Case A -- always has something to resume by definition.
            // $log->amount is deliberately 0 here (the freeze marker),
            // so this month's real ordinary entitlement has to come
            // from monthlyAllowance(), not the row itself.
            if ($today->day === 1) {
                $log->update(['pending_addition' => $this->monthlyAllowance($application)]);
            }

            return;
        }

        $ordinaryAmount = (float) $log->amount;
        $priorTotal = AllowanceLedger::priorWithheldTotal($application->id, $month);

        if ($priorTotal <= 0.0) {
            // Nothing to resume at all -- never shows this month,
            // regardless of day. Corrected explicitly 2026-11-03:
            // "1 hari bulan appeal pun keluar next monthnya" — the
            // 1st removes the *partial-month question* (no CGS figure
            // needed), not the payroll lag itself. 'determined' is what
            // keeps it blank this month and surfaces it next month via
            // rowFor()'s own `$previousStatus === 'determined'` branch,
            // same as everywhere else this module ever defers.
            if ($today->day === 1) {
                $log->update(['status' => 'determined', 'amount' => $ordinaryAmount]);
            } else {
                $log->update(['status' => 'review_required']);
            }

            return;
        }

        $stage1 = $this->governingGate($application);
        $newStatus = $this->statusAsOf($stage1, $today->copy()->endOfMonth());

        $log->update([
            'status' => $newStatus,
            'amount' => $priorTotal,
            'pending_addition' => $today->day === 1 ? $ordinaryAmount : null,
        ]);
    }

    protected function evaluateGate(StageGate $gate): void
    {
        $this->resyncIfUntouched($gate);

        $today = Carbon::today();
        $previousTimeline = $gate->timeline_status;

        // grace_end_date is the rule table's own month count (e.g.
        // deadline_month=3, grace_end_month=6 from service_start_date) —
        // but the grace *reminder* window runs through the whole of that
        // month, not up to its first day. Confirmed explicitly 2026-10-21
        // against the original walkthrough's own worked example (1 April
        // start -> reminders 1 Jul/1 Aug/1 Sep/1 Oct, expiry only 1 Nov):
        // with grace_end_month=6 that's grace_end_date = 1 Oct, and the
        // last reminder has to land ON grace_end_date itself, with expiry
        // not firing until a full month after it. statusAsOf() mirrors
        // this same +1 month boundary for the payment ledger.
        $timeline = match (true) {
            $today->lt($gate->deadline_date->copy()->subMonth()) => 'upcoming',
            $today->lt($gate->deadline_date) => 'due_soon',
            $today->lt($gate->grace_end_date->copy()->addMonth()) => 'grace_period',
            default => 'grace_expired',
        };

        $gate->timeline_status = $timeline;

        // Also fires on the same pass that lands a gate in grace_expired
        // — confirmed explicitly 2026-10-21: "even witheld or terminated,
        // email reminder berapa bulan dari start grace period sampai
        // month terakhir grace period pun ada jugak" — a correction that
        // jumps straight from upcoming into an already-fully-elapsed
        // grace window (last_reminder_sent_at reset to null) still has to
        // produce every reminder that window was ever owed, not just the
        // withheld/termination notice. sendMonthlyGraceReminder() itself
        // caps the backfill at the grace_end_date month, so this never
        // sends a "please complete" reminder for a month that was
        // already past grace.
        if (in_array($timeline, ['grace_period', 'grace_expired'], true)) {
            $this->sendMonthlyGraceReminder($gate);
        }

        if ($timeline === 'grace_expired' && $previousTimeline !== 'grace_expired') {
            $this->applyGraceExpiry($gate);
        }

        $gate->save();
    }

    /**
     * Re-derives deadline_date/grace_end_date from the appointment's
     * *current* service_start_date — but only while the gate is still
     * untouched (not_recorded + eligible). The instant anything real has
     * happened to a gate (completed, withheld, forfeited), it stops
     * qualifying and keeps whatever dates it had at that moment, same as
     * before — this only closes the gap where a corrected service_start_date
     * (e.g. a data-entry fix) silently failed to reach a schedule that
     * hadn't done anything with its old dates yet.
     */
    protected function resyncIfUntouched(StageGate $gate): void
    {
        if ($gate->completion_status !== 'not_recorded' || $gate->allowance_status !== 'eligible') {
            return;
        }

        $startDate = $this->currentStartDate($gate->application);

        if (! $startDate) {
            return;
        }

        $gate->loadMissing('rule');
        $rule = $gate->rule;

        if (! $rule) {
            return;
        }

        $gate->deadline_date = $startDate->copy()->addMonths($rule->deadline_month);
        $gate->grace_end_date = $startDate->copy()->addMonths($rule->grace_end_month);
    }

    protected function currentStartDate(Application $application): ?Carbon
    {
        return $this->detailFor($application)?->service_start_date;
    }

    /** Shared by every method here that needs this appointment's own detail row, GRA or GA. */
    protected function detailFor(Application $application): GraApplicationDetail|GaApplicationDetail|null
    {
        return $application->module_type === 'gra_application'
            ? GraApplicationDetail::where('application_id', $application->id)->first()
            : GaApplicationDetail::where('application_id', $application->id)->first();
    }

    /**
     * Fires exactly once, the day a gate's grace period lapses with nothing
     * recorded. Stage 1 withholds allowance (recoverable later). Stage 2+
     * forfeits it and raises a termination trigger — this module does not
     * decide the student's candidature status itself; see
     * StageGateTerminationTrigger's migration for why.
     *
     * Everyone — student, Admin, CGS, Supervisor, Senior Director — final
     * answer confirmed explicitly 2026-10-22, after this flipped narrow
     * and back twice in one day: "yang dapat email reminders, allowance
     * withheld, allowance recovered, terminated, appeal approved ni
     * semua semua au student, admin, cgs, supervisor, senior director ni
     * stakeholder die." All of them are stakeholders in a withheld
     * allowance or a termination, not just CGS.
     */
    protected function applyGraceExpiry(StageGate $gate): void
    {
        if ($gate->isRecoverable()) {
            $gate->allowance_status = 'withheld';
            $this->notifyStudent($gate, new StageGateAllowanceWithheld($gate, forStudent: true));
            $this->notifyEveryone($gate, new StageGateAllowanceWithheld($gate, forStudent: false));

            return;
        }

        $gate->allowance_status = 'forfeited';

        // Always kept in sync with the current, real state — even on a
        // re-trigger (e.g. after recomputeApplication() resets things and
        // the corrected date still leaves this gate overdue) — independent
        // of whether the trigger row below is a duplicate, so a stale
        // candidature_status can never survive a re-evaluation.
        $this->terminateCandidature($gate->application);

        $alreadyTriggered = StageGateTerminationTrigger::where('stage_gate_id', $gate->id)->exists();

        if ($alreadyTriggered) {
            return;
        }

        $trigger = StageGateTerminationTrigger::create([
            'application_id' => $gate->application_id,
            'student_id' => $gate->application->student_id,
            'stage_gate_id' => $gate->id,
            'reason' => "Stage {$gate->stage_order} ({$gate->stage_name}) grace period ended ".
                "{$gate->grace_end_date->format('j M Y')} without completion being recorded.",
            'triggered_at' => now(),
        ]);

        $this->notifyStudent($gate, new StageGateCandidatureTerminationTriggered($gate, forStudent: true));
        $this->notifyEveryone($gate, new StageGateCandidatureTerminationTriggered($gate, forStudent: false));
    }

    protected function terminateCandidature(Application $application, bool $terminated = true): void
    {
        $this->detailFor($application)?->update(['candidature_status' => $terminated ? 'terminated' : 'active']);
    }

    /**
     * Called from CandidatureAppealController::decide() the moment a
     * Senior Director approves a student's appeal against a termination.
     * Confirmed explicitly 2026-10-15: this reinstates candidature_status
     * only — "status timeline sama and allowance jugak" (timeline_status
     * and allowance_status stay exactly as they were). The gate that
     * triggered the termination is still sitting at whatever
     * completion/allowance state it was in; recovering its allowance is
     * still entirely recordCompletion()'s job, the same as for any
     * Stage 1 withheld gate — an appeal reinstates the student's standing,
     * it does not retroactively mark anything complete.
     */
    public function reinstateCandidature(Application $application): void
    {
        $this->terminateCandidature($application, terminated: false);
    }

    /**
     * Everyone — same final answer as applyGraceExpiry()'s docblock,
     * confirmed explicitly 2026-10-22: the monthly reminder is in that
     * same named list ("yang dapat email reminders, allowance withheld,
     * allowance recovered, terminated, appeal approved ni semua semua").
     * This one flipped narrow-then-broad-then-narrow-then-broad across
     * three separate corrections in one day — notifyEveryone() is the
     * one to trust going forward, not a narrower helper reintroduced
     * later without this exact docblock being updated too.
     *
     * Backfills one reminder per calendar month from the month after the
     * last one sent (or the deadline's own month, if none has been sent
     * yet) through whichever is earlier of this month or the grace_end_date
     * month — never just "this month's" reminder, and never a month past
     * grace either. Confirmed explicitly 2026-10-21: correcting
     * service_start_date to a date whose grace period started months ago
     * (e.g. 1 April, evaluated for the first time on 21 October) has to
     * produce the 1 Jul/1 Aug/1 Sep/1 Oct reminders that would have gone
     * out across that whole window, not just one for "now" — and that
     * same backfill still has to happen even when the gate's current
     * timeline has already moved past grace into grace_expired (evaluateGate()
     * calls this for both states) — "even witheld or terminated... month
     * terakhir grace period pun ada jugak email tu." recomputeApplication()
     * resetting last_reminder_sent_at to null is what makes this loop
     * start from the deadline rather than from wherever it last left off.
     * Same backfill shape as ensureMonthlyLogs(), for the same reason: a
     * gap here would mean a month nobody was ever actually reminded about.
     */
    protected function sendMonthlyGraceReminder(StageGate $gate): void
    {
        $cursor = $gate->last_reminder_sent_at
            ? $gate->last_reminder_sent_at->copy()->addMonthNoOverflow()->startOfMonth()
            : $gate->deadline_date->copy()->startOfMonth();

        $thisMonth = Carbon::today()->startOfMonth();
        $lastGraceMonth = $gate->grace_end_date->copy()->startOfMonth();
        $cap = $thisMonth->lt($lastGraceMonth) ? $thisMonth : $lastGraceMonth;

        while ($cursor->lte($cap)) {
            $this->notifyStudent($gate, new StageGateGraceReminder($gate, forStudent: true));
            $this->notifyEveryone($gate, new StageGateGraceReminder($gate, forStudent: false));
            $cursor->addMonthNoOverflow();
        }

        $gate->last_reminder_sent_at = now();
    }

    /**
     * CGS's "Mark Complete" / "Mark Not Complete" action. Recording it here
     * (rather than the controller writing the columns directly) is what
     * keeps the back-pay calculation and the audit trail attached to the
     * one place completion is ever set.
     */
    public function recordCompletion(StageGate $gate, User $actor, bool $completed, ?string $notes = null): StageGate
    {
        return DB::transaction(function () use ($gate, $actor, $completed, $notes) {
            $gate->completion_status = $completed ? 'completed' : 'not_completed';
            $gate->completion_date = $completed ? now() : null;
            $gate->completed_by = $actor->id;
            $gate->notes = $notes;

            activity('stage_gate')
                ->causedBy($actor)
                ->performedOn($gate)
                ->withProperties([
                    'action' => $completed ? 'MARK_STAGE_GATE_COMPLETE' : 'MARK_STAGE_GATE_NOT_COMPLETE',
                    'application_id' => $gate->application_id,
                    'stage_order' => $gate->stage_order,
                ])
                ->log($completed ? 'Stage Gate marked complete' : 'Stage Gate marked not complete');

            if ($completed && $gate->allowance_status === 'withheld') {
                $this->backPay($gate);
            } elseif ($completed) {
                $gate->allowance_status = 'eligible';
            }

            $gate->save();

            if ($completed) {
                $this->notifyStudent($gate, new StageGateCompleted($gate, forStudent: true));
                $this->notifyEveryone($gate, new StageGateCompleted($gate, forStudent: false));
            }

            return $gate;
        });
    }

    /**
     * Stage 1 only. Every AllowancePaymentLog row between the month after
     * grace ended and the completion month gets restated 'withheld' ->
     * 'backdated' — no cap, per team decision (2026-09-21 conversation).
     */
    protected function backPay(StageGate $gate): void
    {
        $gate->allowance_status = 'backdated';

        AllowancePaymentLog::where('application_id', $gate->application_id)
            ->where('stage_gate_id', $gate->id)
            ->where('status', 'withheld')
            ->get()
            ->each(function (AllowancePaymentLog $log) {
                $log->update(['status' => 'backdated', 'paid_at' => now()]);
            });
    }

    /**
     * The one real anchor for exactly when forfeiture began — not just a
     * boolean "is this application currently terminated." Shared by
     * ensureMonthlyLogs() and recomputeMonthlyLogs() so they can never
     * drift apart on this; originally only in the former (found
     * 2026-10-28 testing a brand-new application's first-ever backfill:
     * a flat boolean applied across a whole backfill wrongly marked 19
     * months 'forfeited', when the real termination only started in the
     * 19th). recomputeMonthlyLogs() had the exact same flaw, just never
     * caught until a real service_start_date correction on an already-
     * terminated application exposed it 2026-11-03: it was blanket-
     * forfeiting every existing row the moment candidature_status
     * flipped back to 'terminated', including months a full year before
     * the real termination point. The earliest currently-forfeited
     * gate's own grace_end_date (+1 month, same boundary evaluateGate()
     * uses) is the one real anchor; every month before it still gets
     * the normal statusAsOf() treatment regardless of today's
     * candidature_status.
     */
    protected function terminatedSinceFor(Application $application): ?Carbon
    {
        if ($this->detailFor($application)?->candidature_status !== 'terminated') {
            return null;
        }

        $graceEnd = StageGate::where('application_id', $application->id)
            ->where('allowance_status', 'forfeited')
            ->orderBy('stage_order')
            ->value('grace_end_date');

        return $graceEnd ? Carbon::parse($graceEnd)->addMonth()->startOfMonth() : null;
    }

    /**
     * Backfills one AllowancePaymentLog row for every calendar month from
     * the last one on record up to this month — never just "today's"
     * month, or a gap here would leave a withheld month with no row to
     * ever back-pay against.
     */
    /**
     * Anchored on service_start_date, not submitted_at — found 2026-10-23
     * on a real application: submitted_at can be much later than the
     * appointment's own schedule (a demo re-seed, a late-approved
     * application, anything), which left this only ever backfilling
     * forward from whatever month the application happened to be
     * submitted in, skipping every real month the schedule itself had
     * already been running — a gate withheld since 1 Nov 2025 showed
     * only a single October 2026 log row, not the 12 months owed.
     * Also now fills *any* missing month in range, not just the ones
     * after the latest existing row — the old "cursor = max + 1 month"
     * approach could never backfill a gap that came before an
     * already-existing later row, which is exactly what left that one
     * October row stranded with nothing before it.
     */
    protected function ensureMonthlyLogs(Application $application): void
    {
        $thisMonth = Carbon::today()->startOfMonth();
        $start = $this->currentStartDate($application)?->copy()->startOfMonth()
            ?? $application->submitted_at?->copy()->startOfMonth()
            ?? $thisMonth;

        if ($start->gt($thisMonth)) {
            return;
        }

        $existing = AllowancePaymentLog::where('application_id', $application->id)
            ->orderBy('payment_month')
            ->get(['payment_month', 'status']);
        $existingByMonth = $existing->keyBy(fn ($l) => Carbon::parse($l->payment_month)->format('Y-m'));

        $amount = $this->monthlyAllowance($application);
        $stage1 = $this->governingGate($application);
        $terminatedSince = $this->terminatedSinceFor($application);

        // The status of whichever month immediately precedes this
        // backfill (existing or just-created) — needed to tell the exact
        // month a termination *starts* in apart from one still ongoing.
        // Confirmed explicitly 2026-10-28: "on bulan die terminate...
        // bulan tu die akan nampak allowance blank dulu, selagi cgs tak
        // tentukan" — the month termination *enters* is a partial-month
        // judgement call for CGS (AllowanceController::setReviewAmount()),
        // since termination can trigger on any day, not just the 1st.
        // The *exit* side (an appeal's reinstatement month) no longer
        // works this way — see resumeReinstatementMonth()'s own docblock
        // for why the two sides ended up asymmetric.
        $previousStatus = $existing->last()?->status;

        $cursor = $start->copy();

        while ($cursor->lte($thisMonth)) {
            $key = $cursor->format('Y-m');

            if (isset($existingByMonth[$key])) {
                $previousStatus = $existingByMonth[$key]->status;
            } else {
                $monthAmount = $amount;

                if ($terminatedSince && $cursor->gte($terminatedSince)) {
                    if ($stage1?->completion_status === 'completed') {
                        // A Stage 1 obligation that's *already complete*
                        // but never confirmed-paid (backdated) stays
                        // *visible* through a termination it has nothing
                        // to do with — "allowance tertunggak... die
                        // still show, hijau, cgs boleh mark complete or
                        // not" — but corrected explicitly 2026-11-03,
                        // same day: that only means it doesn't vanish,
                        // not that it keeps *growing* every month while
                        // genuinely terminated — "bulan after termination
                        // takkan accumulated." Frozen by contributing
                        // zero to the running total (status stays
                        // 'backdated' so rowFor()'s walk doesn't reset
                        // it either) until a later reinstatement resumes
                        // real growth via resumeReinstatementMonth().
                        $status = 'backdated';
                        $monthAmount = 0.0;
                    } else {
                        $status = in_array($previousStatus, ['forfeited', 'review_required', 'determined'], true)
                            ? 'forfeited'
                            : 'review_required';
                    }
                } else {
                    $status = $this->statusAsOf($stage1, $cursor->copy()->endOfMonth());
                }

                AllowancePaymentLog::create([
                    'application_id' => $application->id,
                    'stage_gate_id' => $stage1?->id,
                    'student_id' => $application->student_id,
                    'payment_month' => $cursor->copy(),
                    'amount' => $monthAmount,
                    'status' => $status,
                ]);

                $previousStatus = $status;
            }

            $cursor->addMonthNoOverflow();
        }
    }

    /**
     * What a gate's allowance consequence would have been by the end of a
     * given month. Once the gate is genuinely completed, its own
     * allowance_status (eligible, or backdated if it was ever withheld and
     * recovered) is always more authoritative than a date-only guess —
     * found 2026-10-15 re-deriving a log for a since-completed Stage 1
     * whose grace_end_date was long past: the date-only guess read
     * "withheld" for a gate CGS had already resolved for real. While still
     * open, this never uses "today's" allowance_status, which would
     * mis-mark months backfilled from before a deadline or grace period as
     * already withheld. The +1 month on grace_end_date mirrors
     * evaluateGate()'s own timeline boundary — the grace_end_date month
     * itself is still a reminder month, not yet withheld; see that
     * method's docblock for the worked example this matches.
     */
    protected function statusAsOf(?StageGate $gate, Carbon $monthEnd): string
    {
        if (! $gate) {
            return 'eligible';
        }

        // Settled for good, 2026-11-03, after flipping twice in one
        // session — this order (grace_end_date boundary checked
        // *before* completion) is the one to trust: "yang bug kira dari
        // service start date tu buang ah... anggap je payment before
        // allowance suspended [tu] completed" — a month before the
        // obligation was ever due is assumed already settled, full
        // stop, the same way a brand-new application's very first
        // month is just empty rather than owing anything. A late
        // completion's 'backdated' allowance_status only ever reaches
        // back to grace_end_date + 1 month, never all the way to
        // service_start_date.
        if ($monthEnd->lt($gate->grace_end_date->copy()->addMonth())) {
            return 'eligible';
        }

        if ($gate->completion_status === 'completed') {
            return $gate->allowance_status;
        }

        return $gate->isRecoverable() ? 'withheld' : 'forfeited';
    }

    /**
     * Stage 1 (RPD)'s own gate — the only stage whose allowance_status
     * actually governs payment. Confirmed explicitly 2026-10-15: "allowance
     * ni tertakluk pada stage 1" — Stage 2+ never has its own independent
     * allowance consequence (stage_gate_rules.recoverable is true only for
     * stage_order 1, for every level); a later stage's grace expiry is a
     * *candidature* consequence (termination), handled separately by
     * governingAllowanceStatus() below. Earlier rounds defined this as
     * "the earliest still-open gate", which happened to agree with Stage 1
     * whenever Stage 1 wasn't yet completed, but was wrong the moment
     * Stage 1 completed while a later stage was independently overdue —
     * see governingAllowanceStatus()'s docblock for the fix.
     */
    public function governingGate(Application $application): ?StageGate
    {
        return StageGate::where('application_id', $application->id)
            ->where('stage_order', 1)
            ->first();
    }

    /**
     * The allowance status actually shown/paid for this appointment right
     * now. Two rules, confirmed explicitly 2026-10-15:
     *
     *  - Terminated candidature always reads as forfeited, full stop —
     *    "katakan kalau terminate allowance not eligable" — regardless of
     *    what Stage 1 itself says (Stage 1 could already be long complete
     *    when a later stage's own grace expiry triggered the termination).
     *  - Otherwise, it's purely Stage 1's own allowance_status — "allowance
     *    eligable unless memang rpd die tak siap lagi": once a Senior
     *    Director approves a candidature appeal and reinstateCandidature()
     *    flips the student back to active, allowance goes back to reading
     *    eligible (or withheld/backdated) exactly as Stage 1 says, with no
     *    separate "unfreeze" step needed — reinstateCandidature()
     *    deliberately never touches allowance_status/timeline_status, this
     *    derivation is what makes that correct rather than stuck.
     */
    public function governingAllowanceStatus(Application $application): string
    {
        if ($this->detailFor($application)?->candidature_status === 'terminated') {
            return 'forfeited';
        }

        return $this->governingGate($application)?->allowance_status ?? 'eligible';
    }

    protected function monthlyAllowance(Application $application): float
    {
        return (float) ($this->detailFor($application)?->proposed_allowance ?? 0);
    }

    protected function notifyStudent(StageGate $gate, $notification): void
    {
        $gate->loadMissing('application.student');
        $gate->application->student?->notify($notification);
    }

    /**
     * "Semua user" — confirmed explicitly 2026-10-15: completion,
     * termination, and allowance-suspended all go to everyone in this
     * application's chain, not just the student. Admin + the whole CGS
     * team (Senior Director included) broadly, plus this specific
     * student's own assigned Supervisor — not every supervisor in the
     * system. $notification must accept `forStudent` in its constructor,
     * same pattern every Stage Gate notification already uses; the
     * student's own copy is sent separately by the caller with
     * forStudent: true.
     */
    protected function notifyEveryone(StageGate $gate, $notification): void
    {
        $gate->loadMissing('application.student.supervisor');

        $recipients = User::where('role', Role::ADMIN)->get()
            ->merge(User::whereIn('role', Role::cgsTeam())->get());

        if ($supervisor = $gate->application->student?->supervisor) {
            $recipients->push($supervisor);
        }

        $recipients->unique('id')->each(fn (User $u) => $u->notify($notification));
    }
}
